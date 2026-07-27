<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

/**
 * A voucher reached POSTED. Scalars only — no models cross a module boundary.
 *
 * Fired after the transaction commits (AGENT_BRIEF rule 3), so a listener that
 * queues a job or sends a notification can never observe a voucher that a later
 * rollback erased.
 */
final readonly class JournalEntryPosted
{
    public function __construct(
        public int $journalEntryId,
        public int $organizationId,
        public string $voucherNo,
        public string $entryDate,
        public string $sourceType,
        public int $sourceId,
        public int $totalRial,
        public int $totalFineMg,
    ) {}
}
