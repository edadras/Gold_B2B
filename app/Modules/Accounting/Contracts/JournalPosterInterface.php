<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use App\Modules\Accounting\Domain\SourceType;

/**
 * The only supported way to write into the journal.
 *
 * Implementations own the transaction, verify both balance invariants of §9.2
 * before any row is written, and are idempotent on
 * (organization_id, source_type, source_id).
 */
interface JournalPosterInterface
{
    /**
     * Post a voucher. Safe to call repeatedly for the same source.
     *
     * @throws \App\Modules\Shared\Exceptions\UnbalancedTransactionException when a column does not balance
     * @throws \App\Modules\Accounting\Domain\Exceptions\ClosedPeriodException when the entry date sits in a closed period
     */
    public function post(VoucherDraft $draft): PostingResult;

    /**
     * Post a correction. Identical to post(), except that an entry date falling
     * in a closed period is moved forward into the current open period rather
     * than rejected (§9.7 — «اصلاح با سند اصلاحی در دوره جاری»).
     */
    public function postCorrection(VoucherDraft $draft): PostingResult;

    /**
     * Reverse an existing POSTED voucher with a mirror voucher.
     *
     * Nothing is deleted or edited: §9.7 «حذف — ممنوع؛ فقط REVERSED».
     */
    public function reverse(int $journalEntryId, string $reason, ?int $userId = null): PostingResult;

    /**
     * Whether a voucher already exists for this source.
     *
     * Lets a caller skip expensive preparation — a cost-basis recalculation,
     * say — for an event it has already processed. This is a fast path, not a
     * guarantee: post() remains the authority on idempotency.
     */
    public function existsForSource(int $organizationId, SourceType $sourceType, int $sourceId): bool;
}
