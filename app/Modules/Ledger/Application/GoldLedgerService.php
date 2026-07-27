<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\TransferResult;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Shared\ValueObjects\FineWeight;

/**
 * Gold-typed view of LedgerService.
 *
 * Contains no logic of its own — it exists so callers in Trading, Custody and
 * Settlement work in FineWeight and can never accidentally hand a rial amount
 * to a gold operation.
 */
final class GoldLedgerService implements GoldLedgerInterface
{
    private const ASSET = AssetType::GOLD;

    public function __construct(private readonly LedgerService $ledger) {}

    public function availableBalance(int $orgId): FineWeight
    {
        return FineWeight::fromMilligrams($this->ledger->rawBalance($orgId, self::ASSET, Bucket::AVAILABLE));
    }

    public function balanceIn(int $orgId, Bucket $bucket): FineWeight
    {
        return FineWeight::fromMilligrams($this->ledger->rawBalance($orgId, self::ASSET, $bucket));
    }

    public function totalBalance(int $orgId): FineWeight
    {
        return FineWeight::fromMilligrams($this->ledger->rawBalanceAcross($orgId, self::ASSET));
    }

    public function reserve(int $orgId, FineWeight $amount, LedgerReference $ref): LedgerEntryId
    {
        return $this->ledger->reserve($orgId, $amount, $ref);
    }

    public function release(LedgerEntryId $reservationId, ?FineWeight $partialAmount = null): void
    {
        $this->ledger->release($reservationId, $partialAmount);
    }

    public function moveBucket(
        int $orgId,
        Bucket $from,
        Bucket $to,
        FineWeight $amount,
        LedgerReference $ref,
        ?EntryType $type = null,
    ): TransactionGroup {
        return $this->ledger->moveBucket($orgId, $from, $to, $amount, $ref, $type);
    }

    public function transfer(
        int $fromOrgId,
        int $toOrgId,
        FineWeight $amount,
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
        FineWeight $amount,
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
        FineWeight $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId {
        return $this->ledger->debit($orgId, $bucket, $amount, $type, $ref, $counterparty, $description);
    }

    public function deposit(int $orgId, FineWeight $amount, LedgerReference $ref): LedgerEntryId
    {
        return $this->ledger->credit(
            $orgId,
            Bucket::AVAILABLE,
            $amount,
            EntryType::DEPOSIT_GOLD,
            $ref,
            SystemAccountCode::EXTERNAL_GOLD_IN,
            'Physical gold deposit',
        );
    }

    public function withdraw(int $orgId, FineWeight $amount, LedgerReference $ref): LedgerEntryId
    {
        return $this->ledger->debit(
            $orgId,
            Bucket::AVAILABLE,
            $amount,
            EntryType::WITHDRAW_GOLD,
            $ref,
            SystemAccountCode::EXTERNAL_GOLD_OUT,
            'Physical gold withdrawal',
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
