<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Application\Commands\CountedItem;
use App\Modules\Custody\Application\Results\ReconciliationReport;
use App\Modules\Custody\Application\Results\VarianceLine;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\VarianceClassification;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\ValueObjects\CodeFormat;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\VaultAuditModel;
use App\Modules\Custody\Infrastructure\Models\VaultModel;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * Physical reconciliation — docs/03-domain/06-custody-vault.md §6.6.
 *
 * Thresholds, expressed in basis points of the expected weight
 * (1 bps = 0.01%):
 *
 *   diff == 0                         MATCHED
 *   < tolerance_bps   (< 0.01%)       WITHIN_TOLERANCE        scale noise
 *   <= review_bps     (<= 0.1%)       REVIEW_REQUIRED         adjust w/ approval
 *   >  review_bps     (>  0.1%)       INVESTIGATION_REQUIRED  halt
 *   expected, not found               MISSING                 freeze the vault
 *   found, not expected               UNKNOWN_LOT             halt, trace origin
 *
 * This service classifies and records. It never adjusts the ledger — a
 * variance becomes a ledger movement only after a written decision and dual
 * approval (§6.6 step 5).
 */
final readonly class VaultReconciliationService
{
    public const DEFAULT_TOLERANCE_BPS = 1;    // 0.01%

    public const DEFAULT_REVIEW_BPS = 10;      // 0.1%

    public function __construct(private CustodyOperationRecorder $recorder) {}

    /**
     * @param  list<CountedItem>  $counts
     */
    public function reconcile(
        int $vaultId,
        array $counts,
        int $auditorUserId,
        ?string $notes = null,
    ): ReconciliationReport {
        return DB::transaction(function () use ($vaultId, $counts, $auditorUserId, $notes): ReconciliationReport {
            $vault = VaultModel::query()->find($vaultId);

            if (! $vault instanceof VaultModel) {
                throw new CustodyEntityNotFoundException('Vault', $vaultId);
            }

            $expected = $this->expectedLots($vaultId);

            $byCode = [];
            $bySerial = [];
            foreach ($expected as $lot) {
                $byCode[(string) $lot->lot_code] = $lot;

                if (is_string($lot->serial_number) && $lot->serial_number !== '') {
                    $bySerial[$lot->serial_number] = $lot;
                }
            }

            $lines = [];
            $seenLotIds = [];
            $countedGross = 0;

            foreach ($counts as $item) {
                $countedGross = IntMath::add($countedGross, $item->countedGrossMg);

                $lot = null;

                if ($item->lotCode !== null && isset($byCode[$item->lotCode])) {
                    $lot = $byCode[$item->lotCode];
                } elseif ($item->serialNumber !== null && isset($bySerial[$item->serialNumber])) {
                    $lot = $bySerial[$item->serialNumber];
                }

                if ($lot === null) {
                    $lines[] = new VarianceLine(
                        lotId: null,
                        lotCode: $item->lotCode,
                        serialNumber: $item->serialNumber,
                        expectedGrossMg: null,
                        countedGrossMg: $item->countedGrossMg,
                        differenceMg: $item->countedGrossMg,
                        differenceBps: 0,
                        classification: VarianceClassification::UNKNOWN_LOT,
                        countedLocation: $item->locationCode,
                    );

                    continue;
                }

                $seenLotIds[] = (int) $lot->id;

                $expectedMg = (int) $lot->gross_weight_mg;
                $difference = IntMath::sub($item->countedGrossMg, $expectedMg);
                $bps = $this->differenceBps($expectedMg, $difference);

                $lines[] = new VarianceLine(
                    lotId: (int) $lot->id,
                    lotCode: (string) $lot->lot_code,
                    serialNumber: $lot->serial_number,
                    expectedGrossMg: $expectedMg,
                    countedGrossMg: $item->countedGrossMg,
                    differenceMg: $difference,
                    differenceBps: $bps,
                    classification: $this->classify($difference, $bps),
                    expectedLocation: $lot->physical_location,
                    countedLocation: $item->locationCode,
                );
            }

            foreach ($expected as $lot) {
                if (in_array((int) $lot->id, $seenLotIds, true)) {
                    continue;
                }

                $lines[] = new VarianceLine(
                    lotId: (int) $lot->id,
                    lotCode: (string) $lot->lot_code,
                    serialNumber: $lot->serial_number,
                    expectedGrossMg: (int) $lot->gross_weight_mg,
                    countedGrossMg: null,
                    differenceMg: -((int) $lot->gross_weight_mg),
                    differenceBps: 10_000,
                    classification: VarianceClassification::MISSING,
                    expectedLocation: $lot->physical_location,
                );
            }

            $expectedGross = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->gross_weight_mg, $expected));
            $expectedFine = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->fine_weight_mg, $expected));

            $requiresInvestigation = false;
            $freeze = false;
            foreach ($lines as $line) {
                $requiresInvestigation = $requiresInvestigation || $line->classification->haltsOperations();
                $freeze = $freeze || $line->classification->freezesVault();
            }

            $audit = new VaultAuditModel;
            $audit->audit_code = 'PENDING-'.bin2hex(random_bytes(6));
            $audit->vault_id = $vaultId;
            $audit->status = $requiresInvestigation ? 'ESCALATED' : 'COMPLETED';
            $audit->started_at = now();
            $audit->completed_at = now();
            $audit->expected_lot_count = count($expected);
            $audit->expected_gross_mg = $expectedGross;
            $audit->expected_fine_mg = $expectedFine;
            $audit->counted_lot_count = count($counts);
            $audit->counted_gross_mg = $countedGross;
            $audit->matched_count = $this->countClass($lines, VarianceClassification::MATCHED);
            $audit->within_tolerance_count = $this->countClass($lines, VarianceClassification::WITHIN_TOLERANCE);
            $audit->review_count = $this->countClass($lines, VarianceClassification::REVIEW_REQUIRED);
            $audit->investigation_count = $this->countClass($lines, VarianceClassification::INVESTIGATION_REQUIRED);
            $audit->missing_count = $this->countClass($lines, VarianceClassification::MISSING);
            $audit->unknown_count = $this->countClass($lines, VarianceClassification::UNKNOWN_LOT);
            $audit->variances = array_map(
                static fn (VarianceLine $l): array => $l->toArray(),
                array_values(array_filter($lines, static fn (VarianceLine $l): bool => $l->classification->isVariance())),
            );
            $audit->requires_investigation = $requiresInvestigation;
            $audit->vault_frozen = $freeze;
            $audit->auditor_user_id = $auditorUserId;
            $audit->notes = $notes;
            $audit->save();

            $audit->audit_code = CodeFormat::format(
                (string) CodeFormat::setting('audit_prefix', 'VA-'),
                (int) $audit->id,
            );
            $audit->save();

            $this->recorder->record(
                type: CustodyOperationType::AUDIT_COUNT,
                inputLotIds: array_map(static fn (GoldLotModel $l): int => (int) $l->id, $expected),
                outputLotIds: [],
                inputFineMg: $expectedFine,
                outputFineMg: $expectedFine,
                lossFineMg: 0,
                requestedByUserId: $auditorUserId,
                vaultId: $vaultId,
                reason: 'Physical reconciliation '.$audit->audit_code,
                referenceType: 'vault_audit',
                referenceId: (int) $audit->id,
                executedByUserId: $auditorUserId,
                notes: $notes,
            );

            // A missing lot is a critical incident: stop accepting new metal
            // until the investigation closes (§6.6 threshold table).
            if ($freeze) {
                $vault->status = 'FROZEN';
                $vault->save();
            }

            return new ReconciliationReport(
                auditId: (int) $audit->id,
                auditCode: (string) $audit->audit_code,
                vaultId: $vaultId,
                lines: $lines,
                expectedLotCount: count($expected),
                expectedGrossMg: $expectedGross,
                expectedFineMg: $expectedFine,
                countedLotCount: count($counts),
                countedGrossMg: $countedGross,
                requiresInvestigation: $requiresInvestigation,
                vaultFrozen: $freeze,
            );
        }, attempts: 3);
    }

    /**
     * The list the vault officer walks the floor with — docs §6.6 step 1.
     *
     * @return list<GoldLotModel>
     */
    public function expectedLots(int $vaultId): array
    {
        /** @var list<GoldLotModel> $lots */
        $lots = GoldLotModel::query()
            ->where('custodian_type', CustodianType::VAULT->value)
            ->where('custodian_id', $vaultId)
            ->whereNotIn('status', [LotStatus::CONSUMED->value, LotStatus::WITHDRAWN->value])
            ->orderBy('id')
            ->get()
            ->all();

        return $lots;
    }

    public function classify(int $differenceMg, int $differenceBps): VarianceClassification
    {
        if ($differenceMg === 0) {
            return VarianceClassification::MATCHED;
        }

        if ($differenceBps < $this->toleranceBps()) {
            return VarianceClassification::WITHIN_TOLERANCE;
        }

        if ($differenceBps <= $this->reviewBps()) {
            return VarianceClassification::REVIEW_REQUIRED;
        }

        return VarianceClassification::INVESTIGATION_REQUIRED;
    }

    /** |difference| as basis points of the expected weight, floored. */
    public function differenceBps(int $expectedMg, int $differenceMg): int
    {
        if ($expectedMg <= 0) {
            return $differenceMg === 0 ? 0 : 10_000;
        }

        return IntMath::mulDivFloor(abs($differenceMg), 10_000, $expectedMg);
    }

    /** @param list<VarianceLine> $lines */
    private function countClass(array $lines, VarianceClassification $classification): int
    {
        return count(array_filter($lines, static fn (VarianceLine $l): bool => $l->classification === $classification));
    }

    private function toleranceBps(): int
    {
        return $this->setting('tolerance_bps', self::DEFAULT_TOLERANCE_BPS);
    }

    private function reviewBps(): int
    {
        return $this->setting('review_bps', self::DEFAULT_REVIEW_BPS);
    }

    private function setting(string $key, int $default): int
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return $default;
        }

        return (int) $container->make('config')
            ->get("goldb2b.custody.reconciliation.{$key}", $default);
    }
}
