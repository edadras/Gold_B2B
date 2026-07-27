<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Contracts;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Shared\ValueObjects\Rial;

/**
 * Typed facade over the rial ledger.
 *
 * Mirrors GoldLedgerInterface, with the PAYABLE bucket and the fee helper that
 * only make sense for money.
 */
interface RialLedgerInterface
{
    public function availableBalance(int $orgId): Rial;

    public function balanceIn(int $orgId, Bucket $bucket): Rial;

    /** Net position across all five rial buckets, PAYABLE included. */
    public function netBalance(int $orgId): Rial;

    public function reserve(int $orgId, Rial $amount, LedgerReference $ref): LedgerEntryId;

    public function release(LedgerEntryId $reservationId, ?Rial $partialAmount = null): void;

    public function moveBucket(
        int $orgId,
        Bucket $from,
        Bucket $to,
        Rial $amount,
        LedgerReference $ref,
        ?EntryType $type = null,
    ): TransactionGroup;

    public function transfer(
        int $fromOrgId,
        int $toOrgId,
        Rial $amount,
        LedgerReference $ref,
        Bucket $fromBucket = Bucket::AVAILABLE,
        Bucket $toBucket = Bucket::AVAILABLE,
        ?EntryType $debitType = null,
        ?EntryType $creditType = null,
    ): TransferResult;

    public function credit(
        int $orgId,
        Bucket $bucket,
        Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId;

    public function debit(
        int $orgId,
        Bucket $bucket,
        Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId;

    public function deposit(int $orgId, Rial $amount, LedgerReference $ref): LedgerEntryId;

    public function withdraw(int $orgId, Rial $amount, LedgerReference $ref): LedgerEntryId;

    /** Member − fee / SYSTEM FEE_INCOME + fee, in one balanced group. */
    public function chargeFee(int $orgId, Rial $fee, LedgerReference $ref, ?string $description = null): LedgerEntryId;

    public function reverse(LedgerEntryId $entryId, string $reason, int $requestedBy, int $approvedBy): LedgerEntryId;

    public function rebuildBalance(int $orgId, Bucket $bucket): int;
}
