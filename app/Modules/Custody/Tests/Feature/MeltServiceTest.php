<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\Commands\MeltLotsCommand;
use App\Modules\Custody\Application\Commands\MeltOutputSpec;
use App\Modules\Custody\Application\MeltService;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Domain\Exceptions\IncompatibleLotsException;
use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;
use App\Modules\Custody\Events\LotMelted;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\LotLineageModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Melt — docs/03-domain/02-gold-lot-assay.md §2.6 case (b).
 *
 * Hard rule under test: the output of a MELT is always DECLARED / UNDER_ASSAY
 * and therefore untradable until a certificate arrives.
 */
#[Group('custody')]
#[Group('melt')]
final class MeltServiceTest extends CustodyTestCase
{
    private function service(): MeltService
    {
        return app(MeltService::class);
    }

    #[Test]
    public function melt_output_is_under_assay_declared_and_not_tradable(): void
    {
        // The worked figures from §2.6: 40g@750, 35g@750, 25g@995.
        $a = $this->makeLot(grossMg: 40_000, purityX10: 7_500);
        $b = $this->makeLot(grossMg: 35_000, purityX10: 7_500);
        $c = $this->makeLot(grossMg: 25_000, purityX10: 9_950);

        $inputFine = (int) $a->fine_weight_mg + (int) $b->fine_weight_mg + (int) $c->fine_weight_mg;
        $this->assertSame(81_125, $inputFine);

        $result = $this->service()->melt(new MeltLotsCommand(
            lotIds: [(int) $a->id, (int) $b->id, (int) $c->id],
            outputs: [
                new MeltOutputSpec(
                    gross: Weight::fromMilligrams(99_500),
                    declaredPurity: Purity::fromScaled(8_130),
                ),
            ],
            requestedByUserId: 6,
            reason: 'Consolidation melt',
        ));

        $this->assertCount(1, $result->outputLotIds);

        $output = GoldLotModel::query()->findOrFail($result->outputLotIds[0]);

        $this->assertSame(LotStatus::UNDER_ASSAY, $output->status);
        $this->assertSame(PuritySource::DECLARED, $output->purity_source);
        $this->assertSame(OriginType::MELT, $output->origin_type);
        $this->assertNull($output->current_assay_id);

        // Not tradable, not collateral, not allocatable.
        $this->assertFalse($output->purity_source->isTradableOnOrderBook());
        $this->assertFalse($output->purity_source->isEligibleAsCollateral());
        $this->assertFalse($output->status->isAllocatable());
        $this->assertFalse($output->toSnapshot()->isOrderBookEligible());

        // 99,500 mg at declared purity 813 is 80,893 mg fine (F1 floors).
        $this->assertSame(80_893, (int) $output->fine_weight_mg);
        $this->assertSame($inputFine - 80_893, $result->fineLossMg);
        $this->assertTrue($result->conserves());

        foreach ([$a, $b, $c] as $input) {
            $this->assertSame(LotStatus::CONSUMED, $input->fresh()->status);
        }
    }

    #[Test]
    public function it_records_the_loss_on_the_operation_and_the_event(): void
    {
        Event::fake([LotMelted::class]);

        $a = $this->makeLot(grossMg: 100_000, purityX10: 9_950);
        $inputFine = (int) $a->fine_weight_mg;

        $result = $this->service()->melt(new MeltLotsCommand(
            lotIds: [(int) $a->id],
            outputs: [
                new MeltOutputSpec(
                    gross: Weight::fromMilligrams(99_000),
                    declaredPurity: Purity::fromScaled(9_950),
                ),
            ],
            requestedByUserId: 2,
            refinerId: null,
        ));

        $operation = CustodyOperationModel::query()->findOrFail($result->operationId);
        $this->assertSame($inputFine, $operation->input_fine_mg);
        $this->assertSame($result->outputFineMg, $operation->output_fine_mg);
        $this->assertSame($result->fineLossMg, $operation->loss_fine_mg);
        $this->assertTrue($operation->conserves());
        $this->assertSame(1_000, (int) $operation->loss_gross_mg);

        Event::assertDispatched(
            LotMelted::class,
            static fn (LotMelted $e): bool => $e->lossFineMg === $result->fineLossMg
                && $e->inputFineMg === $result->inputFineMg
                && $e->outputFineMg === $result->outputFineMg,
        );
    }

    #[Test]
    public function it_flags_operations_whose_loss_exceeds_the_approval_threshold(): void
    {
        // 2% loss, well over the 0.5% extra-approval threshold in §6.5.
        $a = $this->makeLot(grossMg: 100_000, purityX10: 10_000);

        $result = $this->service()->melt(new MeltLotsCommand(
            lotIds: [(int) $a->id],
            outputs: [
                new MeltOutputSpec(
                    gross: Weight::fromMilligrams(98_000),
                    declaredPurity: Purity::fromScaled(10_000),
                ),
            ],
            requestedByUserId: 2,
        ));

        $operation = CustodyOperationModel::query()->findOrFail($result->operationId);
        $this->assertTrue((bool) $operation->requires_extra_approval);
    }

    #[Test]
    public function it_links_every_input_to_every_output_in_the_lineage(): void
    {
        $a = $this->makeLot(grossMg: 50_000, purityX10: 9_000);
        $b = $this->makeLot(grossMg: 50_000, purityX10: 8_000);

        $result = $this->service()->melt(new MeltLotsCommand(
            lotIds: [(int) $a->id, (int) $b->id],
            outputs: [
                new MeltOutputSpec(gross: Weight::fromMilligrams(49_000), declaredPurity: Purity::fromScaled(8_500)),
                new MeltOutputSpec(gross: Weight::fromMilligrams(49_000), declaredPurity: Purity::fromScaled(8_500)),
            ],
            requestedByUserId: 1,
        ));

        $this->assertSame(4, LotLineageModel::query()->count());

        foreach ($result->outputLotIds as $outputId) {
            $this->assertSame(2, LotLineageModel::query()->where('child_lot_id', $outputId)->count());
        }
    }

    #[Test]
    public function it_refuses_to_melt_gold_belonging_to_several_members(): void
    {
        $a = $this->makeLot(grossMg: 10_000, purityX10: 9_950, ownerOrgId: 184);
        $b = $this->makeLot(grossMg: 10_000, purityX10: 9_950, ownerOrgId: 291);

        $this->expectException(IncompatibleLotsException::class);

        $this->service()->melt(new MeltLotsCommand(
            lotIds: [(int) $a->id, (int) $b->id],
            outputs: [new MeltOutputSpec(gross: Weight::fromMilligrams(19_000), declaredPurity: Purity::fromScaled(9_950))],
            requestedByUserId: 1,
        ));
    }

    #[Test]
    public function it_refuses_an_output_heavier_than_the_input(): void
    {
        $a = $this->makeLot(grossMg: 10_000, purityX10: 9_950);

        $this->expectException(InvalidLotOperationException::class);

        $this->service()->melt(new MeltLotsCommand(
            lotIds: [(int) $a->id],
            outputs: [new MeltOutputSpec(gross: Weight::fromMilligrams(11_000), declaredPurity: Purity::fromScaled(9_950))],
            requestedByUserId: 1,
        ));
    }

    #[Test]
    public function the_output_can_be_routed_straight_to_the_laboratory(): void
    {
        $a = $this->makeLot(grossMg: 20_000, purityX10: 9_000, custodianType: CustodianType::VAULT, custodianId: 1);

        $result = $this->service()->melt(new MeltLotsCommand(
            lotIds: [(int) $a->id],
            outputs: [new MeltOutputSpec(gross: Weight::fromMilligrams(19_800), declaredPurity: Purity::fromScaled(9_000))],
            requestedByUserId: 1,
            outputCustodianType: CustodianType::LAB,
            outputCustodianId: 3,
        ));

        $output = GoldLotModel::query()->findOrFail($result->outputLotIds[0]);
        $this->assertSame(CustodianType::LAB, $output->custodian_type);
        $this->assertTrue($output->custodian_type->isLocked());
        $this->assertNull($output->vault_box_id);
    }
}
