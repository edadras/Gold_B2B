<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Contracts\TransferResult;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Shared\ValueObjects\Rial;

/**
 * Rial-typed view of LedgerService. Mirror of GoldLedgerService.
 */
final class RialLedgerService implements RialLedgerInterface
{
    private const ASSET = AssetType::RIAL;

    public function __construct(private readonly LedgerService $ledger) {}

    public function availableBalance(int $orgId): Rial
    {
        return Rial::fromRial($this->ledger->rawBalance($orgId, self::ASSET, Bucket::AVAILABLE));
    }

    public function balanceIn(int $orgId, Bucket $bucket): Rial
    {
        return Rial::fromRial($this->ledger->rawBalance($orgId, self::ASSET, $bucket));
    }

    public function netBalance(int $orgId): Rial
    {
        return Rial::fromRial($this->ledger->rawBalanceAcross($orgId, self::ASSET));
    }

    public function reserve(int $orgId, Rial $amount, LedgerReference $ref): LedgerEntryId
    {
        return $this->ledger->reserve($orgId, $amount, $ref);
    }

    public function release(LedgerEntryId $reservationId, ?Rial $partialAmount = null): void
    {
        $this->ledger->release($reservationId, $partialAmount);
    }

    public function moveBucket(
        int $orgId,
        Bucket $from,
        Bucket $to,
        Rial $amount,
        LedgerReference $ref,
        ?EntryType $type = null,
    ): TransactionGroup {
        return $this->ledger->moveBucket($orgId, $from, $to, $amount, $ref, $type);
    }

    public function transfer(
        int $fromOrgId,
        int $toOrgId,
        Rial $amount,
        LedgerReference $ref,
        Bucket $fromBucket = Bucket::AVAILABLE,
        Bucket $toBucket = Bucket::AVAILABLE,
        ?EntryType $debitType = null,
        ?EntryType $creditType = null,
    ): TransferResult {
        return $this->ledger->transfer($fromOrgId, $toOrgId, $amount, $ref, $fromBucket, $toBucket, $debitType, $creditType);
    }

    public function credit(
        int $orgId,
        Bucket $bucket,
        Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId {
        return $this->ledger->credit($orgId, $bucket, $amount, $type, $ref, $counterparty, $description);
    }

    public function debit(
        int $orgId,
        Bucket $bucket,
        Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId {
        return $this->ledger->debit($orgId, $bucket, $amount, $type, $ref, $counterparty, $description);
    }

    public function deposit(int $orgId, Rial $amount, LedgerReference $ref): LedgerEntryId
    {
        return $this->ledger->credit(
            $orgId,
            Bucket::AVAILABLE,
            $amount,
            EntryType::DEPOSIT_CASH,
            $ref,
            SystemAccountCode::EXTERNAL_CASH_IN,
            'Cash deposit',
        );
    }

    public function withdraw(int $orgId, Rial $amount, LedgerReference $ref): LedgerEntryId
    {
        return $this->ledger->debit(
            $orgId,
            Bucket::AVAILABLE,
            $amount,
            EntryType::WITHDRAW_CASH,
            $ref,
            SystemAccountCode::EXTERNAL_CASH_OUT,
            'Cash withdrawal',
        );
    }

    /**
     * Member − fee / SYSTEM FEE_INCOME + fee, exactly as worked example 1 (g5).
     */
    public function chargeFee(int $orgId, Rial $fee, LedgerReference $ref, ?string $description = null): LedgerEntryId
    {
        return $this->ledger->debit(
            $orgId,
            Bucket::AVAILABLE,
            $fee,
            EntryType::FEE_CHARGE,
            $ref,
            SystemAccountCode::FEE_INCOME,
            $description ?? 'Platform fee',
        );
    }

    public function reverse(LedgerEntryId $entryId, string $reason, int $requestedBy, int $approvedBy): LedgerEntryId
    {
        return $this->ledger->reverse($entryId, $reason, $requestedBy, $approvedBy);
    }

    public function rebuildBalance(int $orgId, Bucket $bucket): int
    {
        return $this->ledger->rebuildBalance($orgId, self::ASSET, $bucket);
    }
}
