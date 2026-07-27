<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\Commands\SplitLotCommand;
use App\Modules\Custody\Application\Commands\SplitPart;
use App\Modules\Custody\Application\SplitService;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Events\LotSplit;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\LotLineageModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Split invariants — docs/03-domain/02-gold-lot-assay.md §2.5 and the arithmetic
 * of worked example 2 (docs/11-appendix/03-worked-examples.md).
 */
#[Group('custody')]
#[Group('split')]
final class SplitServiceTest extends CustodyTestCase
{
    private function service(): SplitService
    {
        return app(SplitService::class);
    }

    #[Test]
    public function it_reproduces_worked_example_two_exactly(): void
    {
        // GL-00001512: gross 502,513 mg | purity 9950 | fine 500,000 mg | owner 184
        $parent = $this->makeLot(grossMg: 502_513, purityX10: 9_950, ownerOrgId: 184);

        $this->assertSame(500_000, (int) $parent->fine_weight_mg, 'F1 fine weight of the parent');

        $result = $this->service()->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [SplitPart::byFine(FineWeight::fromMilligrams(250_000))],
            requestedByUserId: 7,
            reason: 'Split for settlement STL-00088232',
        ));

        $this->assertSame([251_257, 251_256], $result->childGrossMg);
        $this->assertSame([250_000, 249_999], $result->childFineMg);
        $this->assertSame(499_999, $result->totalChildFineMg());
        $this->assertSame(0, $result->grossLossMg);

        // The single milligram the ledger has to post as ROUNDING.
        $this->assertSame(1, $result->fineLossMg);
        $this->assertTrue($result->conserves());

        $operation = CustodyOperationModel::query()->findOrFail($result->operationId);
        $this->assertSame(500_000, $operation->input_fine_mg);
        $this->assertSame(499_999, $operation->output_fine_mg);
        $this->assertSame(1, $operation->loss_fine_mg);
        $this->assertTrue($operation->conserves());

        // The children inherit purity, owner and custodian; the parent is gone.
        foreach ($result->childLotIds as $childId) {
            $child = GoldLotModel::query()->findOrFail($childId);
            $this->assertSame(9_950, (int) $child->purity_x10);
            $this->assertSame(184, (int) $child->owner_organization_id);
            $this->assertSame(OriginType::SPLIT, $child->origin_type);
            $this->assertSame(2, (int) $child->generation);
        }

        $this->assertSame(LotStatus::CONSUMED, $parent->fresh()->status);
    }

    #[Test]
    public function the_parent_is_consumed_and_never_reachable_again(): void
    {
        $parent = $this->makeLot(grossMg: 200_000, purityX10: 7_500);

        $result = $this->service()->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [SplitPart::byGross(Weight::fromMilligrams(80_000))],
            requestedByUserId: 3,
        ));

        $parent->refresh();
        $this->assertSame(LotStatus::CONSUMED, $parent->status);
        $this->assertTrue($parent->status->isFinal());

        // A consumed lot cannot be split a second time.
        $this->expectException(LotNotAvailableException::class);

        $this->service()->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [SplitPart::byGross(Weight::fromMilligrams(1_000))],
            requestedByUserId: 3,
        ));

        $this->assertNotEmpty($result->childLotIds);
    }

    #[Test]
    public function it_writes_one_lineage_row_per_child(): void
    {
        $parent = $this->makeLot(grossMg: 300_000, purityX10: 9_990);

        $result = $this->service()->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [
                SplitPart::byGross(Weight::fromMilligrams(100_000)),
                SplitPart::byGross(Weight::fromMilligrams(100_000)),
            ],
            requestedByUserId: 1,
        ));

        $this->assertCount(3, $result->childLotIds, 'two requested parts plus the remainder');

        $edges = LotLineageModel::query()->where('parent_lot_id', $parent->id)->get();
        $this->assertCount(3, $edges);

        foreach ($edges as $edge) {
            $this->assertContains((int) $edge->child_lot_id, $result->childLotIds);
            $this->assertSame($result->operationId, (int) $edge->operation_id);
        }
    }

    #[Test]
    public function it_reports_physical_loss_on_top_of_rounding_loss(): void
    {
        $parent = $this->makeLot(grossMg: 100_000, purityX10: 7_500);
        $parentFine = (int) $parent->fine_weight_mg;

        $result = $this->service()->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [SplitPart::byGross(Weight::fromMilligrams(40_000))],
            requestedByUserId: 5,
            physicalLossGrossMg: 150,
            reason: 'Saw loss',
        ));

        $this->assertSame(150, $result->grossLossMg);
        $this->assertSame(99_850, $result->totalChildGrossMg());
        $this->assertSame($parentFine - $result->totalChildFineMg(), $result->fineLossMg);
        $this->assertGreaterThanOrEqual(112, $result->fineLossMg, '150 mg gross at purity 750 is 112 mg fine');
        $this->assertTrue($result->conserves());
    }

    #[Test]
    public function it_emits_the_fine_loss_on_the_lot_split_event(): void
    {
        Event::fake([LotSplit::class]);

        $parent = $this->makeLot(grossMg: 502_513, purityX10: 9_950);

        $result = $this->service()->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [SplitPart::byFine(FineWeight::fromMilligrams(250_000))],
            requestedByUserId: 9,
        ));

        Event::assertDispatched(
            LotSplit::class,
            static fn (LotSplit $e): bool => $e->parentLotId === $result->parentLotId
                && $e->fineLossMg === 1
                && $e->grossLossMg === 0
                && $e->isRoundingOnly()
                && $e->childLotIds === $result->childLotIds,
        );
    }

    #[Test]
    public function it_refuses_children_heavier_than_the_parent(): void
    {
        $parent = $this->makeLot(grossMg: 50_000, purityX10: 9_950);

        $this->expectException(InvalidLotOperationException::class);

        $this->service()->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [SplitPart::byGross(Weight::fromMilligrams(60_000))],
            requestedByUserId: 1,
        ));
    }

    #[Test]
    public function children_inherit_the_custodian_and_the_location(): void
    {
        $vault = $this->makeVault();
        $box = $this->makeBox($vault);

        $parent = $this->makeLot(
            grossMg: 120_000,
            purityX10: 9_990,
            custodianType: CustodianType::VAULT,
            custodianId: (int) $vault->id,
            overrides: [
                'vault_box_id' => $box->id,
                'physical_location' => $box->full_code,
                'purity_source' => PuritySource::ASSAYED->value,
            ],
        );

        $result = $this->service()->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [SplitPart::byGross(Weight::fromMilligrams(60_000))],
            requestedByUserId: 2,
        ));

        foreach ($result->childLotIds as $childId) {
            $child = GoldLotModel::query()->findOrFail($childId);
            $this->assertSame(CustodianType::VAULT, $child->custodian_type);
            $this->assertSame((int) $vault->id, (int) $child->custodian_id);
            $this->assertSame((int) $box->id, (int) $child->vault_box_id);
            $this->assertSame($box->full_code, $child->physical_location);
            $this->assertSame(PuritySource::ASSAYED, $child->purity_source);
        }
    }
}
