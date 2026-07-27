<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\Commands\MergeLotsCommand;
use App\Modules\Custody\Application\MergeService;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Domain\Exceptions\IncompatibleLotsException;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Domain\Exceptions\MixedPurityMergeException;
use App\Modules\Custody\Events\LotsMerged;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\LotLineageModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** Logical merge — docs/03-domain/02-gold-lot-assay.md §2.6 case (a). */
#[Group('custody')]
#[Group('merge')]
final class MergeServiceTest extends CustodyTestCase
{
    private function service(): MergeService
    {
        return app(MergeService::class);
    }

    #[Test]
    public function it_rejects_mixed_purity_and_points_the_caller_at_melt(): void
    {
        // The exact scenario drawn in §2.6: 750, 750 and 995.
        $a = $this->makeLot(grossMg: 40_000, purityX10: 7_500);
        $b = $this->makeLot(grossMg: 35_000, purityX10: 7_500);
        $c = $this->makeLot(grossMg: 25_000, purityX10: 9_950);

        try {
            $this->service()->merge(new MergeLotsCommand(
                lotIds: [(int) $a->id, (int) $b->id, (int) $c->id],
                requestedByUserId: 1,
            ));

            $this->fail('a mixed-purity merge must be rejected');
        } catch (MixedPurityMergeException $e) {
            $this->assertSame('MIXED_PURITY_MERGE', $e->errorCode());
            $this->assertSame([7_500, 9_950], $e->distinctPurities);
            $this->assertSame('MELT', $e->details()['remedy']);
            $this->assertStringContainsString('MELT', $e->getMessage());
        }

        // Nothing was consumed — the whole operation rolled back.
        foreach ([$a, $b, $c] as $lot) {
            $this->assertSame(LotStatus::AVAILABLE, $lot->fresh()->status);
        }

        $this->assertSame(0, CustodyOperationModel::query()->count());
    }

    #[Test]
    public function it_merges_same_purity_lots_without_losing_a_milligram(): void
    {
        $a = $this->makeLot(grossMg: 40_000, purityX10: 7_500);
        $b = $this->makeLot(grossMg: 35_000, purityX10: 7_500);
        $c = $this->makeLot(grossMg: 25_000, purityX10: 7_500);

        $expectedFine = (int) $a->fine_weight_mg + (int) $b->fine_weight_mg + (int) $c->fine_weight_mg;

        $result = $this->service()->merge(new MergeLotsCommand(
            lotIds: [(int) $c->id, (int) $a->id, (int) $b->id],
            requestedByUserId: 4,
        ));

        $this->assertSame(100_000, $result->grossWeightMg);
        $this->assertSame($expectedFine, $result->fineWeightMg);
        $this->assertSame(7_500, $result->purityX10);
        $this->assertTrue($result->conserves());

        $merged = GoldLotModel::query()->findOrFail($result->newLotId);
        $this->assertSame(OriginType::MERGE, $merged->origin_type);
        $this->assertSame(LotStatus::AVAILABLE, $merged->status);
        $this->assertSame(2, (int) $merged->generation);
        $this->assertNull($merged->current_assay_id);

        foreach ([$a, $b, $c] as $input) {
            $this->assertSame(LotStatus::CONSUMED, $input->fresh()->status);
        }

        $this->assertSame(
            3,
            LotLineageModel::query()->where('child_lot_id', $result->newLotId)->count(),
        );

        $operation = CustodyOperationModel::query()->findOrFail($result->operationId);
        $this->assertSame(0, $operation->loss_fine_mg);
        $this->assertTrue($operation->conserves());
    }

    #[Test]
    public function it_rejects_lots_belonging_to_different_owners(): void
    {
        $a = $this->makeLot(grossMg: 10_000, purityX10: 9_950, ownerOrgId: 184);
        $b = $this->makeLot(grossMg: 10_000, purityX10: 9_950, ownerOrgId: 291);

        $this->expectException(IncompatibleLotsException::class);

        $this->service()->merge(new MergeLotsCommand(
            lotIds: [(int) $a->id, (int) $b->id],
            requestedByUserId: 1,
        ));
    }

    #[Test]
    public function it_rejects_lots_held_by_different_custodians(): void
    {
        $a = $this->makeLot(grossMg: 10_000, purityX10: 9_950, custodianType: CustodianType::VAULT, custodianId: 1);
        $b = $this->makeLot(grossMg: 10_000, purityX10: 9_950, custodianType: CustodianType::ORGANIZATION, custodianId: 184);

        $this->expectException(IncompatibleLotsException::class);

        $this->service()->merge(new MergeLotsCommand(
            lotIds: [(int) $a->id, (int) $b->id],
            requestedByUserId: 1,
        ));
    }

    #[Test]
    public function it_refuses_to_merge_a_reserved_lot(): void
    {
        $a = $this->makeLot(grossMg: 10_000, purityX10: 9_950);
        $b = $this->makeLot(grossMg: 10_000, purityX10: 9_950, status: LotStatus::RESERVED);

        $this->expectException(LotNotAvailableException::class);

        $this->service()->merge(new MergeLotsCommand(
            lotIds: [(int) $a->id, (int) $b->id],
            requestedByUserId: 1,
        ));
    }

    #[Test]
    public function the_merged_lot_inherits_the_weakest_purity_source(): void
    {
        $a = $this->makeLot(grossMg: 10_000, purityX10: 9_950, puritySource: PuritySource::ASSAYED);
        $b = $this->makeLot(grossMg: 10_000, purityX10: 9_950, puritySource: PuritySource::DECLARED);

        $result = $this->service()->merge(new MergeLotsCommand(
            lotIds: [(int) $a->id, (int) $b->id],
            requestedByUserId: 1,
        ));

        $merged = GoldLotModel::query()->findOrFail($result->newLotId);
        $this->assertSame(PuritySource::DECLARED, $merged->purity_source);
        $this->assertFalse($merged->purity_source->isTradableOnOrderBook());
    }

    #[Test]
    public function it_emits_lots_merged(): void
    {
        Event::fake([LotsMerged::class]);

        $a = $this->makeLot(grossMg: 10_000, purityX10: 9_950);
        $b = $this->makeLot(grossMg: 20_000, purityX10: 9_950);

        $result = $this->service()->merge(new MergeLotsCommand(
            lotIds: [(int) $a->id, (int) $b->id],
            requestedByUserId: 1,
        ));

        Event::assertDispatched(
            LotsMerged::class,
            static fn (LotsMerged $e): bool => $e->newLotId === $result->newLotId
                && $e->parentLotIds === [(int) $a->id, (int) $b->id]
                && $e->grossWeightMg === 30_000,
        );
    }
}
