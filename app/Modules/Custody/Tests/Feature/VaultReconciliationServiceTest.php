<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\Commands\CountedItem;
use App\Modules\Custody\Application\VaultReconciliationService;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\VarianceClassification;
use App\Modules\Custody\Infrastructure\Models\VaultAuditModel;
use App\Modules\Custody\Infrastructure\Models\VaultModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\Weight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Physical reconciliation — docs/03-domain/06-custody-vault.md §6.6.
 *
 * | weight diff < 0.01%  | record, no action                        |
 * | 0.01% .. 0.1%        | review, adjust with approval             |
 * | > 0.1%               | halt, full investigation                 |
 * | lot missing          | critical, freeze the vault, investigate  |
 * | unknown lot          | halt, trace its origin                   |
 */
#[Group('custody')]
#[Group('reconciliation')]
final class VaultReconciliationServiceTest extends CustodyTestCase
{
    private function service(): VaultReconciliationService
    {
        return app(VaultReconciliationService::class);
    }

    /** @return iterable<string, array{int, int, VarianceClassification}> */
    public static function thresholds(): iterable
    {
        // expected 1,000,000 mg -> 1 bps is 100 mg.
        yield 'exact match' => [1_000_000, 1_000_000, VarianceClassification::MATCHED];
        yield 'below 0.01% is scale noise' => [1_000_000, 999_950, VarianceClassification::WITHIN_TOLERANCE];
        yield 'exactly 0.01% needs review' => [1_000_000, 999_900, VarianceClassification::REVIEW_REQUIRED];
        yield 'within 0.01-0.1% needs review' => [1_000_000, 999_500, VarianceClassification::REVIEW_REQUIRED];
        yield 'exactly 0.1% still needs review' => [1_000_000, 999_000, VarianceClassification::REVIEW_REQUIRED];
        yield 'above 0.1% halts' => [1_000_000, 998_000, VarianceClassification::INVESTIGATION_REQUIRED];
        yield 'a heavy piece halts too' => [1_000_000, 1_002_000, VarianceClassification::INVESTIGATION_REQUIRED];
    }

    #[Test]
    #[DataProvider('thresholds')]
    public function it_classifies_weight_variance_by_the_documented_thresholds(
        int $expectedMg,
        int $countedMg,
        VarianceClassification $expected,
    ): void {
        $service = $this->service();
        $difference = $countedMg - $expectedMg;

        $this->assertSame(
            $expected,
            $service->classify($difference, $service->differenceBps($expectedMg, $difference)),
        );
    }

    #[Test]
    public function a_clean_count_produces_no_variances(): void
    {
        $vault = $this->makeVault();
        $box = $this->makeBox($vault);

        $lots = [];
        foreach ([500_300, 499_850] as $gross) {
            $lots[] = $this->makeLot(
                grossMg: $gross,
                purityX10: 9_950,
                custodianId: (int) $vault->id,
                overrides: ['vault_box_id' => $box->id, 'physical_location' => $box->full_code],
            );
        }

        $report = $this->service()->reconcile(
            (int) $vault->id,
            array_map(
                static fn ($lot): CountedItem => CountedItem::fromScan(
                    (string) $lot->lot_code,
                    Weight::fromMilligrams((int) $lot->gross_weight_mg),
                    $lot->physical_location,
                ),
                $lots,
            ),
            auditorUserId: 40,
        );

        $this->assertTrue($report->isClean());
        $this->assertFalse($report->requiresInvestigation);
        $this->assertFalse($report->vaultFrozen);
        $this->assertSame(2, $report->countOf(VarianceClassification::MATCHED));
        $this->assertSame(1_000_150, $report->expectedGrossMg);
        $this->assertSame(1_000_150, $report->countedGrossMg);
        $this->assertMatchesRegularExpression('/^VA-\d{8}$/', $report->auditCode);

        $audit = VaultAuditModel::query()->findOrFail($report->auditId);
        $this->assertSame('COMPLETED', $audit->status);
    }

    #[Test]
    public function a_missing_lot_freezes_the_vault(): void
    {
        $vault = $this->makeVault();

        $present = $this->makeLot(grossMg: 500_300, purityX10: 9_950, custodianId: (int) $vault->id);
        $missing = $this->makeLot(grossMg: 500_100, purityX10: 9_950, custodianId: (int) $vault->id);

        $report = $this->service()->reconcile(
            (int) $vault->id,
            [CountedItem::fromScan((string) $present->lot_code, Weight::fromMilligrams(500_300))],
            auditorUserId: 40,
        );

        $this->assertTrue($report->vaultFrozen);
        $this->assertTrue($report->requiresInvestigation);
        $this->assertSame(1, $report->countOf(VarianceClassification::MISSING));

        $missingLines = $report->linesOf(VarianceClassification::MISSING);
        $this->assertSame((int) $missing->id, $missingLines[0]->lotId);
        $this->assertNull($missingLines[0]->countedGrossMg);

        $this->assertSame('FROZEN', VaultModel::query()->findOrFail($vault->id)->status);
        $this->assertSame('ESCALATED', VaultAuditModel::query()->findOrFail($report->auditId)->status);
    }

    #[Test]
    public function a_piece_that_is_not_on_the_books_halts_the_count(): void
    {
        $vault = $this->makeVault();
        $known = $this->makeLot(grossMg: 100_000, purityX10: 9_950, custodianId: (int) $vault->id);

        $report = $this->service()->reconcile(
            (int) $vault->id,
            [
                CountedItem::fromScan((string) $known->lot_code, Weight::fromMilligrams(100_000)),
                CountedItem::fromSerial('SN-2025-9001', Weight::fromMilligrams(250_000)),
            ],
            auditorUserId: 40,
        );

        $this->assertSame(1, $report->countOf(VarianceClassification::UNKNOWN_LOT));
        $this->assertTrue($report->requiresInvestigation);

        // An unknown piece is not a missing piece: the vault is not frozen.
        $this->assertFalse($report->vaultFrozen);

        $unknown = $report->linesOf(VarianceClassification::UNKNOWN_LOT)[0];
        $this->assertNull($unknown->lotId);
        $this->assertSame('SN-2025-9001', $unknown->serialNumber);
    }

    #[Test]
    public function it_reproduces_the_variance_report_from_the_document(): void
    {
        $vault = $this->makeVault();
        $box = $this->makeBox($vault, 'S03', 'F02', 'B14');
        $location = (string) $box->full_code;

        // GL-a matches; GL-b is 200 mg light on 499,850, which is 0.04% and
        // therefore a reviewable variance; GL-c is missing; and an
        // unregistered serial turns up on the shelf.
        //
        // Note the report drawn in §6.6 flags a 20 mg difference on a 500 g
        // bar with a warning triangle, but 20 mg is 0.004% — below the 0.01%
        // threshold the same document sets, so the classifier records it and
        // moves on. The thresholds table wins over the illustration.
        $a = $this->makeLot(grossMg: 500_300, purityX10: 9_950, custodianId: (int) $vault->id, overrides: ['physical_location' => $location]);
        $b = $this->makeLot(grossMg: 499_850, purityX10: 9_950, custodianId: (int) $vault->id, overrides: ['physical_location' => $location]);
        $c = $this->makeLot(grossMg: 500_100, purityX10: 9_950, custodianId: (int) $vault->id, overrides: ['physical_location' => $location]);

        $report = $this->service()->reconcile(
            (int) $vault->id,
            [
                CountedItem::fromScan((string) $a->lot_code, Weight::fromMilligrams(500_300), $location),
                CountedItem::fromScan((string) $b->lot_code, Weight::fromMilligrams(499_650), $location),
                CountedItem::fromSerial('SN-2025-9001', Weight::fromMilligrams(1_000), $location),
            ],
            auditorUserId: 40,
        );

        $this->assertSame(1, $report->countOf(VarianceClassification::MATCHED));
        $this->assertSame(1, $report->countOf(VarianceClassification::REVIEW_REQUIRED));
        $this->assertSame(1, $report->countOf(VarianceClassification::MISSING));
        $this->assertSame(1, $report->countOf(VarianceClassification::UNKNOWN_LOT));

        $review = $report->linesOf(VarianceClassification::REVIEW_REQUIRED)[0];
        $this->assertSame((int) $b->id, $review->lotId);
        $this->assertSame(-200, $review->differenceMg);
        $this->assertSame(4, $review->differenceBps);

        $this->assertSame((int) $c->id, $report->linesOf(VarianceClassification::MISSING)[0]->lotId);

        $audit = VaultAuditModel::query()->findOrFail($report->auditId);
        $this->assertCount(3, (array) $audit->variances, 'the clean line is not a variance');
        $this->assertTrue((bool) $audit->vault_frozen);
    }

    #[Test]
    public function it_flags_a_piece_found_in_the_wrong_box(): void
    {
        $vault = $this->makeVault();
        $home = $this->makeBox($vault, 'S01', 'F01', 'B01');
        $elsewhere = $this->makeBox($vault, 'S02', 'F01', 'B09');

        $lot = $this->makeLot(
            grossMg: 100_000,
            purityX10: 9_950,
            custodianId: (int) $vault->id,
            overrides: ['vault_box_id' => $home->id, 'physical_location' => $home->full_code],
        );

        $report = $this->service()->reconcile(
            (int) $vault->id,
            [CountedItem::fromScan((string) $lot->lot_code, Weight::fromMilligrams(100_000), (string) $elsewhere->full_code)],
            auditorUserId: 40,
        );

        $this->assertSame(VarianceClassification::MATCHED, $report->lines[0]->classification);
        $this->assertTrue($report->lines[0]->isMisplaced());
    }

    #[Test]
    public function consumed_and_withdrawn_lots_are_not_expected_on_the_shelf(): void
    {
        $vault = $this->makeVault();

        $live = $this->makeLot(grossMg: 100_000, purityX10: 9_950, custodianId: (int) $vault->id);
        $this->makeLot(grossMg: 100_000, purityX10: 9_950, status: LotStatus::CONSUMED, custodianId: (int) $vault->id);
        $this->makeLot(grossMg: 100_000, purityX10: 9_950, status: LotStatus::WITHDRAWN, custodianId: (int) $vault->id);

        $expected = $this->service()->expectedLots((int) $vault->id);

        $this->assertCount(1, $expected);
        $this->assertSame((int) $live->id, (int) $expected[0]->id);
    }
}
