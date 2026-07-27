<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Contracts;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;

/**
 * The one ledger API, covering both asset classes.
 *
 * Amounts are always value objects, never bare ints: FineWeight selects the
 * gold ledger, Rial selects the rial ledger. That is what lets a single
 * interface serve both assets without an AssetType parameter on every call —
 * handing a Rial to a gold operation is a type error, which is precisely the
 * mistake the Shared value objects exist to make impossible.
 *
 * Read methods do take an explicit AssetType, because there is no amount to
 * infer from. (DEVIATION from the module brief, which shows
 * `availableBalance(int $orgId)`; the typed facades below restore that exact
 * shape. Without the parameter the generic interface would be ambiguous.)
 *
 * Every mutating method here:
 *   - runs inside a database transaction,
 *   - takes the accounts it touches FOR UPDATE in ascending id order,
 *   - ends by asserting Σ(amount) per asset over its transaction_group is 0
 *     (docs/03-domain/03-ledger.md §3.3, invariant I1).
 */
interface LedgerInterface
{
    public function availableBalance(int $orgId, AssetType $asset): FineWeight|Rial;

    public function balanceIn(int $orgId, AssetType $asset, Bucket $bucket): FineWeight|Rial;

    /** Raw signed balance in the asset's smallest unit — milligrams or rial. */
    public function rawBalance(int $orgId, AssetType $asset, Bucket $bucket): int;

    /**
     * AVAILABLE → RESERVED.
     *
     * @return LedgerEntryId the credit leg landing in RESERVED, which callers
     *                       persist (orders.reservation_entry_id) and later hand to release()
     *
     * @throws \App\Modules\Shared\Exceptions\InsufficientBalanceException
     */
    public function reserve(int $orgId, FineWeight|Rial $amount, LedgerReference $ref): LedgerEntryId;

    /**
     * RESERVED → AVAILABLE, either the whole reservation or part of it.
     *
     * Releasing more than remains outstanding on the reservation is rejected,
     * so repeated partial releases can never over-return.
     */
    public function release(LedgerEntryId $reservationId, FineWeight|Rial|null $partialAmount = null): void;

    /**
     * Move value between two buckets of the same organisation and asset.
     *
     * @param  ?EntryType  $type  defaults to the type implied by the transition
     */
    public function moveBucket(
        int $orgId,
        Bucket $from,
        Bucket $to,
        FineWeight|Rial $amount,
        LedgerReference $ref,
        ?EntryType $type = null,
    ): TransactionGroup;

    /**
     * Move value from one organisation to another.
     *
     * @throws \App\Modules\Ledger\Domain\Exceptions\SelfTransferException when the orgs are the same
     */
    public function transfer(
        int $fromOrgId,
        int $toOrgId,
        FineWeight|Rial $amount,
        LedgerReference $ref,
        Bucket $fromBucket = Bucket::AVAILABLE,
        Bucket $toBucket = Bucket::AVAILABLE,
        ?EntryType $debitType = null,
        ?EntryType $creditType = null,
    ): TransferResult;

    /**
     * Increase an organisation's balance, offset against a system account so
     * the system-wide total per asset stays zero (invariant I4).
     */
    public function credit(
        int $orgId,
        Bucket $bucket,
        FineWeight|Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId;

    /** Decrease an organisation's balance, offset against a system account. */
    public function debit(
        int $orgId,
        Bucket $bucket,
        FineWeight|Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId;

    /**
     * Post the exact opposite of an existing entry and record the link row in
     * ledger_reversals. Four-eyes: requester and approver must differ.
     *
     * @throws \App\Modules\Ledger\Domain\Exceptions\AlreadyReversedException
     */
    public function reverse(LedgerEntryId $entryId, string $reason, int $requestedBy, int $approvedBy): LedgerEntryId;

    /**
     * Recompute the cached balance from the entries themselves and store it.
     *
     * @return int the recomputed balance
     */
    public function rebuildBalance(int $orgId, AssetType $asset, Bucket $bucket): int;

    /**
     * Open a transaction_group, let the caller post whatever legs the operation
     * needs through the supplied writer, then assert the group balances.
     *
     * This is how other modules compose multi-leg operations — a trade posts
     * eight entries in one group — without reimplementing the invariant check.
     *
     * @param  callable(GroupWriter): void  $fn
     */
    public function postGroup(callable $fn): TransactionGroup;
}
