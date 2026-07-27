<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

/**
 * Reproduces the per-account tamper-evidence chain of `ledger_entries`
 * (docs/03-domain/03-ledger.md §3.7, invariant I10):
 *
 *     row_hash = sha256(prev_hash | created_at | account_id | amount
 *                       | entry_type | reference | balance_after)
 *
 * ⚠️ This is a deliberate duplicate of Ledger\Domain\HashChainBuilder. Admin may
 * depend on Shared and Identity only, and Ledger publishes no contract for
 * posting a manual adjustment, so the posting adapter has to write the rows
 * itself. The moment Ledger exposes such a contract this class must be deleted
 * and the adapter re-pointed at it — a divergence here would break the nightly
 * chain verification, which is the whole point of the chain.
 */
final class LedgerRowHasher
{
    public const SEPARATOR = '|';

    public const ALGORITHM = 'sha256';

    public function compute(
        ?string $prevHash,
        string $createdAt,
        int $accountId,
        int $amount,
        string $entryType,
        string $reference,
        int $balanceAfter,
    ): string {
        return hash(self::ALGORITHM, implode(self::SEPARATOR, [
            $prevHash ?? '',
            $createdAt,
            (string) $accountId,
            (string) $amount,
            $entryType,
            $reference,
            (string) $balanceAfter,
        ]));
    }
}
