<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Contracts;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Shared\ValueObjects\FineWeight;

/**
 * Typed facade over the gold ledger.
 *
 * This is what Trading, Custody and Settlement should depend on: every amount
 * is a FineWeight, so a rial value cannot reach it even by accident, and the
 * asset parameter disappears from every signature.
 */
interface GoldLedgerInterface
{
    public function availableBalance(int $orgId): FineWeight;

    public function balanceIn(int $orgId, Bucket $bucket): FineWeight;

    /** Total across AVAILABLE + RESERVED + IN_SETTLEMENT + IN_DISPUTE. */
    public function totalBalance(int $orgId): FineWeight;

    public function reserve(int $orgId, FineWeight $amount, LedgerReference $ref): LedgerEntryId;

    public function release(LedgerEntryId $reservationId, ?FineWeight $partialAmount = null): void;

    public function moveBucket(
        int $orgId,
        Bucket $from,
        Bucket $to,
        FineWeight $amount,
        LedgerReference $ref,
        ?EntryType $type = null,
    ): TransactionGroup;

    public function transfer(
        int $fromOrgId,
        int $toOrgId,
        FineWeight $amount,
        LedgerReference $ref,
        Bucket $fromBucket = Bucket::AVAILABLE,
        Bucket $toBucket = Bucket::AVAILABLE,
        ?EntryType $debitType = null,
        ?EntryType $creditType = null,
    ): TransferResult;

    public function credit(
        int $orgId,
        Bucket $bucket,
        FineWeight $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId;

    public function debit(
        int $orgId,
        Bucket $bucket,
        FineWeight $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId;

    /** Physical gold arriving in the vault: member + / EXTERNAL_GOLD_IN −. */
    public function deposit(int $orgId, FineWeight $amount, LedgerReference $ref): LedgerEntryId;

    /** Physical gold leaving the vault: member − / EXTERNAL_GOLD_OUT +. */
    public function withdraw(int $orgId, FineWeight $amount, LedgerReference $ref): LedgerEntryId;

    public function reverse(LedgerEntryId $entryId, string $reason, int $requestedBy, int $approvedBy): LedgerEntryId;

    public function rebuildBalance(int $orgId, Bucket $bucket): int;
}
