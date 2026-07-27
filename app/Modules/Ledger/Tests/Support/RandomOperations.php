<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Support;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A deterministic random walk over the whole ledger API.
 *
 * Property tests need traffic that a hand-written scenario would never produce:
 * partial releases of already-partly-released reservations, reversals of legs
 * that other operations later touch, transfers that fail halfway through a
 * sequence. Every operation is attempted and every DomainException is swallowed
 * — a rejected operation is a normal outcome and, crucially, must leave the
 * ledger exactly as it found it.
 *
 * Seeded with mt_srand so a failure is reproducible from the seed alone.
 */
trait RandomOperations
{
    /** @var array<int, int> reservation entry ids created during the walk */
    private array $reservations = [];

    /** @var array<string, int> tally of what actually happened, for diagnostics */
    private array $operationTally = [];

    /** @param array<int, int> $organizationIds */
    protected function seedStartingBalances(array $organizationIds, int $goldMg = 1_000_000, int $rial = 5_000_000_000): void
    {
        foreach ($organizationIds as $orgId) {
            $this->goldLedger()->deposit($orgId, self::fine($goldMg), LedgerReference::custody($orgId));
            $this->rialLedger()->deposit($orgId, self::money($rial), LedgerReference::custody($orgId));
        }
    }

    /**
     * @param  array<int, int>  $organizationIds
     * @return array<string, int> tally of attempted operations by name
     */
    protected function runRandomOperations(array $organizationIds, int $iterations, int $seed): array
    {
        mt_srand($seed);
        $this->reservations = [];
        $this->operationTally = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->performRandomOperation($organizationIds, $i);
        }

        return $this->operationTally;
    }

    /** @param array<int, int> $organizationIds */
    private function performRandomOperation(array $organizationIds, int $iteration): void
    {
        $operations = [
            'deposit_gold', 'deposit_rial', 'withdraw_gold', 'withdraw_rial',
            'reserve_gold', 'reserve_rial', 'release', 'release_partial',
            'settlement_lock', 'settlement_unlock',
            'transfer_gold', 'transfer_rial',
            'charge_fee', 'rounding', 'dispute_hold', 'dispute_release',
            'reverse',
        ];

        $operation = $operations[mt_rand(0, count($operations) - 1)];
        $this->operationTally[$operation] = ($this->operationTally[$operation] ?? 0) + 1;

        $org = $organizationIds[mt_rand(0, count($organizationIds) - 1)];
        $ref = LedgerReference::of('test', $iteration + 1);

        try {
            match ($operation) {
                'deposit_gold' => $this->goldLedger()->deposit($org, self::fine(mt_rand(1, 200_000)), $ref),
                'deposit_rial' => $this->rialLedger()->deposit($org, self::money(mt_rand(1, 500_000_000)), $ref),
                'withdraw_gold' => $this->goldLedger()->withdraw($org, self::fine(mt_rand(1, 300_000)), $ref),
                'withdraw_rial' => $this->rialLedger()->withdraw($org, self::money(mt_rand(1, 800_000_000)), $ref),
                'reserve_gold' => $this->rememberReservation(
                    $this->goldLedger()->reserve($org, self::fine(mt_rand(1, 400_000)), $ref)
                ),
                'reserve_rial' => $this->rememberReservation(
                    $this->rialLedger()->reserve($org, self::money(mt_rand(1, 900_000_000)), $ref)
                ),
                'release' => $this->releaseRandom(false),
                'release_partial' => $this->releaseRandom(true),
                'settlement_lock' => $this->goldLedger()->moveBucket(
                    $org, Bucket::RESERVED, Bucket::IN_SETTLEMENT, self::fine(mt_rand(1, 150_000)), $ref
                ),
                'settlement_unlock' => $this->goldLedger()->moveBucket(
                    $org, Bucket::IN_SETTLEMENT, Bucket::AVAILABLE, self::fine(mt_rand(1, 150_000)), $ref
                ),
                'transfer_gold' => $this->transferRandom($organizationIds, $org, $ref, true),
                'transfer_rial' => $this->transferRandom($organizationIds, $org, $ref, false),
                'charge_fee' => $this->rialLedger()->chargeFee($org, self::money(mt_rand(1, 50_000_000)), $ref),
                'rounding' => $this->goldLedger()->debit(
                    $org, Bucket::AVAILABLE, self::fine(mt_rand(1, 5)), EntryType::ROUNDING, $ref
                ),
                'dispute_hold' => $this->rialLedger()->moveBucket(
                    $org, Bucket::AVAILABLE, Bucket::IN_DISPUTE, self::money(mt_rand(1, 100_000_000)), $ref
                ),
                'dispute_release' => $this->rialLedger()->moveBucket(
                    $org, Bucket::IN_DISPUTE, Bucket::AVAILABLE, self::money(mt_rand(1, 100_000_000)), $ref
                ),
                'reverse' => $this->reverseRandom(),
                default => null,
            };
        } catch (DomainException|InvalidArgumentException) {
            // Rejected operations are expected. The invariants asserted after
            // the walk are what prove the rejection changed nothing.
        }
    }

    private function rememberReservation(LedgerEntryId $id): void
    {
        $this->reservations[] = $id->value;
    }

    private function releaseRandom(bool $partial): void
    {
        if ($this->reservations === []) {
            return;
        }

        $entryId = $this->reservations[mt_rand(0, count($this->reservations) - 1)];
        $entry = DB::table('ledger_entries')->where('id', $entryId)->first();

        if ($entry === null) {
            return;
        }

        $amount = $partial
            ? max(1, intdiv((int) $entry->amount, mt_rand(2, 4)))
            : null;

        $isGold = $entry->asset_type === 'GOLD';

        $isGold
            ? $this->goldLedger()->release(
                LedgerEntryId::fromInt($entryId),
                $amount === null ? null : self::fine($amount),
            )
            : $this->rialLedger()->release(
                LedgerEntryId::fromInt($entryId),
                $amount === null ? null : self::money($amount),
            );
    }

    /** @param array<int, int> $organizationIds */
    private function transferRandom(array $organizationIds, int $from, LedgerReference $ref, bool $gold): void
    {
        $candidates = array_values(array_filter($organizationIds, static fn (int $id): bool => $id !== $from));

        if ($candidates === []) {
            return;
        }

        $to = $candidates[mt_rand(0, count($candidates) - 1)];

        $gold
            ? $this->goldLedger()->transfer($from, $to, self::fine(mt_rand(1, 250_000)), $ref)
            : $this->rialLedger()->transfer($from, $to, self::money(mt_rand(1, 600_000_000)), $ref);
    }

    private function reverseRandom(): void
    {
        $entry = DB::table('ledger_entries as e')
            ->leftJoin('ledger_reversals as r', 'r.original_entry_id', '=', 'e.id')
            ->whereNull('r.id')
            ->where('e.entry_type', '!=', EntryType::REVERSAL->value)
            ->inRandomOrder()
            ->select('e.id', 'e.asset_type')
            ->first();

        if ($entry === null) {
            return;
        }

        $id = LedgerEntryId::fromInt((int) $entry->id);

        $entry->asset_type === 'GOLD'
            ? $this->goldLedger()->reverse($id, 'random walk', 7, 9)
            : $this->rialLedger()->reverse($id, 'random walk', 7, 9);
    }
}
