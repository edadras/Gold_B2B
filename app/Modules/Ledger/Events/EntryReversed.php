<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Events;

/**
 * An entry was reversed. The original row is untouched — the link lives in
 * ledger_reversals (docs/03-domain/03-ledger.md §3.6, option الف).
 */
final readonly class EntryReversed
{
    public function __construct(
        public int $originalEntryId,
        public int $reversalEntryId,
        public int $accountId,
        public int $organizationId,
        public string $assetType,
        public int $reversedAmount,
        public string $reason,
        public int $requestedByUserId,
        public int $approvedByUserId,
        public string $transactionGroup,
    ) {}
}
