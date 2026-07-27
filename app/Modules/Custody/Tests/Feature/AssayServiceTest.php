<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\AssayService;
use App\Modules\Custody\Application\Commands\RecordAssayCommand;
use App\Modules\Custody\Contracts\AssayReaderInterface;
use App\Modules\Custody\Domain\Enums\AccreditationLevel;
use App\Modules\Custody\Domain\Enums\AssayMethod;
use App\Modules\Custody\Domain\Enums\AssayStatus;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Events\AssayAdjusted;
use App\Modules\Custody\Events\AssayRecorded;
use App\Modules\Custody\Infrastructure\Models\AssayModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** Certificates and re-assay — docs/03-domain/02-gold-lot-assay.md §2.4, §2.9. */
#[Group('custody')]
#[Group('assay')]
final class AssayServiceTest extends CustodyTestCase
{
    private function service(): AssayService
    {
        return app(AssayService::class);
    }

    #[Test]
    public function a_reassay_supersedes_the_old_certificate_and_reports_the_delta(): void
    {
        Event::fake([AssayRecorded::class, AssayAdjusted::class]);

        $lab = $this->makeLaboratory(AccreditationLevel::TIER_1);

        // The §2.4 scenario: purity 995 re-assayed at 985.
        $lot = $this->makeLot(grossMg: 1_000_000, purityX10: 9_950);
        $this->assertSame(995_000, (int) $lot->fine_weight_mg);

        $first = $this->service()->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'IR-LAB03-2025-8821',
            method: AssayMethod::FIRE_ASSAY,
            grossWeight: Weight::fromMilligrams(1_000_000),
            purity: Purity::fromScaled(9_950),
            assayedAt: '2026-01-02 10:30:00',
            recordedByUserId: 11,
        ));

        $this->assertFalse($first->isReAssay());
        $this->assertSame(995_000, $first->newFineMg);

        $second = $this->service()->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'IR-LAB03-2025-8999',
            method: AssayMethod::FIRE_ASSAY,
            grossWeight: Weight::fromMilligrams(1_000_000),
            purity: Purity::fromScaled(9_850),
            assayedAt: '2026-01-20 09:00:00',
            recordedByUserId: 11,
            reason: 'Dispute re-assay',
        ));

        // The old certificate is superseded, not deleted.
        $old = AssayModel::query()->findOrFail($first->assayId);
        $this->assertSame(AssayStatus::SUPERSEDED, $old->status);
        $this->assertSame($second->assayId, (int) $old->superseded_by_id);

        $new = AssayModel::query()->findOrFail($second->assayId);
        $this->assertSame(AssayStatus::VALID, $new->status);

        // The lot is restated from the new certificate.
        $lot->refresh();
        $this->assertSame(9_850, (int) $lot->purity_x10);
        $this->assertSame(985_000, (int) $lot->fine_weight_mg);
        $this->assertSame($second->assayId, (int) $lot->current_assay_id);

        // And the signed delta the Ledger has to post.
        $this->assertTrue($second->isReAssay());
        $this->assertSame(995_000, $second->previousFineMg);
        $this->assertSame(985_000, $second->newFineMg);
        $this->assertSame(-10_000, $second->fineDeltaMg);

        Event::assertDispatched(
            AssayAdjusted::class,
            static fn (AssayAdjusted $e): bool => $e->fineDeltaMg === -10_000
                && $e->previousAssayId === $first->assayId
                && $e->newAssayId === $second->assayId
                && $e->previousPurityX10 === 9_950
                && $e->newPurityX10 === 9_850,
        );

        Event::assertDispatchedTimes(AssayRecorded::class, 2);
        Event::assertDispatchedTimes(AssayAdjusted::class, 1);
    }

    #[Test]
    public function a_first_assay_never_emits_an_adjustment(): void
    {
        Event::fake([AssayAdjusted::class]);

        $lab = $this->makeLaboratory();
        $lot = $this->makeLot(grossMg: 100_000, purityX10: 7_500);

        $this->service()->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'C-1',
            method: AssayMethod::XRF,
            grossWeight: Weight::fromMilligrams(100_000),
            purity: Purity::fromScaled(7_200),
            assayedAt: '2026-01-02 10:30:00',
            recordedByUserId: 1,
        ));

        Event::assertNotDispatched(AssayAdjusted::class);
    }

    #[Test]
    public function a_reassay_that_changes_nothing_does_not_ask_the_ledger_for_a_zero_entry(): void
    {
        Event::fake([AssayAdjusted::class]);

        $lab = $this->makeLaboratory();
        $lot = $this->makeLot(grossMg: 100_000, purityX10: 9_990);

        foreach (['C-1', 'C-2'] as $certificate) {
            $this->service()->record(new RecordAssayCommand(
                goldLotId: (int) $lot->id,
                laboratoryId: (int) $lab->id,
                certificateNo: $certificate,
                method: AssayMethod::ICP,
                grossWeight: Weight::fromMilligrams(100_000),
                purity: Purity::fromScaled(9_990),
                assayedAt: '2026-01-02 10:30:00',
                recordedByUserId: 1,
            ));
        }

        // ledger_entries has CHECK (amount <> 0) — a zero delta must stay quiet.
        Event::assertNotDispatched(AssayAdjusted::class);
    }

    #[Test]
    public function a_certificate_releases_a_lot_from_under_assay(): void
    {
        $lab = $this->makeLaboratory();
        $lot = $this->makeLot(
            grossMg: 200_000,
            purityX10: 9_000,
            status: LotStatus::UNDER_ASSAY,
            puritySource: PuritySource::DECLARED,
        );

        $result = $this->service()->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'C-9',
            method: AssayMethod::FIRE_ASSAY,
            grossWeight: Weight::fromMilligrams(200_000),
            purity: Purity::fromScaled(9_120),
            assayedAt: '2026-01-02 10:30:00',
            recordedByUserId: 1,
        ));

        $lot->refresh();
        $this->assertSame(LotStatus::AVAILABLE, $lot->status);
        $this->assertSame(PuritySource::ASSAYED, $lot->purity_source);
        $this->assertTrue($lot->purity_source->isTradableOnOrderBook());
        $this->assertSame('AVAILABLE', $result->lotStatus);
    }

    #[Test]
    public function an_unaccredited_laboratory_can_only_produce_declared_purity(): void
    {
        $lab = $this->makeLaboratory(AccreditationLevel::UNACCREDITED, 'LAB-UNACC');
        $lot = $this->makeLot(grossMg: 50_000, purityX10: 9_000, status: LotStatus::UNDER_ASSAY);

        $result = $this->service()->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'C-77',
            method: AssayMethod::XRF,
            grossWeight: Weight::fromMilligrams(50_000),
            purity: Purity::fromScaled(9_100),
            assayedAt: '2026-01-02 10:30:00',
            recordedByUserId: 1,
        ));

        $this->assertSame(PuritySource::DECLARED->value, $result->puritySource);
        $this->assertFalse($lot->fresh()->purity_source->isTradableOnOrderBook());
    }

    #[Test]
    public function the_reader_exposes_the_current_certificate_and_the_full_history(): void
    {
        $lab = $this->makeLaboratory();
        $lot = $this->makeLot(grossMg: 100_000, purityX10: 9_950);

        $first = $this->service()->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'H-1',
            method: AssayMethod::XRF,
            grossWeight: Weight::fromMilligrams(100_000),
            purity: Purity::fromScaled(9_950),
            assayedAt: '2026-01-02 10:30:00',
            recordedByUserId: 1,
        ));

        $second = $this->service()->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'H-2',
            method: AssayMethod::FIRE_ASSAY,
            grossWeight: Weight::fromMilligrams(100_000),
            purity: Purity::fromScaled(9_900),
            assayedAt: '2026-02-02 10:30:00',
            recordedByUserId: 1,
        ));

        $reader = app(AssayReaderInterface::class);

        $current = $reader->currentForLot((int) $lot->id);
        $this->assertNotNull($current);
        $this->assertSame($second->assayId, $current->id);
        $this->assertTrue($current->isAuthoritative());
        $this->assertSame($lab->name, $current->laboratoryName);

        $history = $reader->historyForLot((int) $lot->id);
        $this->assertCount(2, $history);
        $this->assertSame($second->assayId, $history[0]->id);
        $this->assertSame($first->assayId, $history[1]->id);
        $this->assertFalse($history[1]->isAuthoritative());
    }

    #[Test]
    public function assay_codes_follow_the_documented_format(): void
    {
        $lab = $this->makeLaboratory();
        $lot = $this->makeLot(grossMg: 10_000, purityX10: 9_950);

        $result = $this->service()->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'F-1',
            method: AssayMethod::XRF,
            grossWeight: Weight::fromMilligrams(10_000),
            purity: Purity::fromScaled(9_950),
            assayedAt: '2026-01-02 10:30:00',
            recordedByUserId: 1,
        ));

        $this->assertMatchesRegularExpression('/^AS-\d{8}$/', $result->assayCode);
    }
}
