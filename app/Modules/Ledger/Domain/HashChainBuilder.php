<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * Per-account tamper-evidence chain (invariant I10).
 *
 * Each row hashes its own immutable fields together with the previous row's
 * hash, so editing any historical row invalidates every hash after it on that
 * account. Verification runs nightly at 03:00 (docs/03-domain/03-ledger.md §3.7).
 *
 *     row_hash = sha256(prev_hash | created_at | account_id | amount
 *                       | entry_type | reference | balance_after)
 *
 * Fields are joined with "|" after each component is cast to its canonical
 * string form; a NULL prev_hash (the first row on an account) contributes an
 * empty component so the genesis row still has a well-defined hash.
 */
final class HashChainBuilder
{
    public const SEPARATOR = '|';

    public const ALGORITHM = 'sha256';

    /**
     * @param  string  $createdAt  microsecond timestamp, 'Y-m-d H:i:s.u'
     * @param  string  $reference  LedgerReference::key(), i.e. "trade:88231"
     */
    public function compute(
        ?string $prevHash,
        string $createdAt,
        int $accountId,
        int $amount,
        string $entryType,
        string $reference,
        int $balanceAfter,
    ): string {
        return hash(self::ALGORITHM, $this->payload(
            $prevHash,
            $createdAt,
            $accountId,
            $amount,
            $entryType,
            $reference,
            $balanceAfter,
        ));
    }

    /** The exact pre-image, exposed so failures can be diffed in a report. */
    public function payload(
        ?string $prevHash,
        string $createdAt,
        int $accountId,
        int $amount,
        string $entryType,
        string $reference,
        int $balanceAfter,
    ): string {
        return implode(self::SEPARATOR, [
            $prevHash ?? '',
            $createdAt,
            (string) $accountId,
            (string) $amount,
            $entryType,
            $reference,
            (string) $balanceAfter,
        ]);
    }

    /**
     * Recompute a chain over rows already read from storage.
     *
     * @param  iterable<int, array{id: int, prev_hash: ?string, row_hash: string, created_at: string, account_id: int, amount: int, entry_type: string, reference: string, balance_after: int}>  $rows
     *   ordered by id ascending, all on the same account
     * @return array<int, int> ids of rows whose stored hash does not match
     */
    public function verify(iterable $rows): array
    {
        $broken = [];
        $expectedPrev = null;
        $first = true;

        foreach ($rows as $row) {
            // A break in linkage is itself corruption, but keep walking with the
            // stored value so one bad row does not report every later row too.
            if (! $first && $row['prev_hash'] !== $expectedPrev) {
                $broken[] = $row['id'];
                $expectedPrev = $row['row_hash'];
                continue;
            }

            $computed = $this->compute(
                $row['prev_hash'],
                $row['created_at'],
                $row['account_id'],
                $row['amount'],
                $row['entry_type'],
                $row['reference'],
                $row['balance_after'],
            );

            if (! hash_equals($row['row_hash'], $computed)) {
                $broken[] = $row['id'];
            }

            $expectedPrev = $row['row_hash'];
            $first = false;
        }

        return $broken;
    }
}
