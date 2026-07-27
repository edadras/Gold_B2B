<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Contracts\TransferResult;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\Direction;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\Exceptions\AlreadyReversedException;
use App\Modules\Ledger\Domain\Exceptions\InvalidReleaseException;
use App\Modules\Ledger\Domain\Exceptions\LedgerAccountNotFoundException;
use App\Modules\Ledger\Domain\Exceptions\LedgerEntryNotFoundException;
use App\Modules\Ledger\Domain\Exceptions\SelfTransferException;
use App\Modules\Ledger\Domain\HashChainBuilder;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Ledger\Events\BalanceReserved;
use App\Modules\Ledger\Events\EntryReversed;
use App\Modules\Ledger\Events\ReservationReleased;
use App\Modules\Ledger\Events\TransferCompleted;
use App\Modules\Ledger\Infrastructure\Locking\AccountLocker;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use App\Modules\Ledger\Infrastructure\Models\LedgerBalanceModel;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntryModel;
use App\Modules\Ledger\Infrastructure\Models\LedgerReversalModel;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Exceptions\UnbalancedTransactionException;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only thing in the system that writes to ledger_entries.
 *
 * Contract of every public mutating method (docs/03-domain/03-ledger.md §3.6,
 * docs/06-backend-laravel/02-implementation-guide.md §2.4):
 *
 *   1. open a transaction, retrying up to three times on deadlock;
 *   2. take every account it will touch FOR UPDATE, ascending by id;
 *   3. post all legs into a single transaction_group;
 *   4. assertGroupBalances() — Σ(amount) per asset over the group must be 0;
 *   5. dispatch domain events only after the transaction has returned.
 *
 * Step 4 is what turns worked example 7 (a forgotten counter-leg) from silent
 * corruption into a rollback.
 */
final class LedgerService implements LedgerInterface
{
    public function __construct(
        private readonly AccountLocker $locker,
        private readonly HashChainBuilder $hashChain,
    ) {}

    // ── reads ────────────────────────────────────────────────────────────────

    public function availableBalance(int $orgId, AssetType $asset): FineWeight|Rial
    {
        return $this->balanceIn($orgId, $asset, Bucket::AVAILABLE);
    }

    public function balanceIn(int $orgId, AssetType $asset, Bucket $bucket): FineWeight|Rial
    {
        return $asset->wrap($this->rawBalance($orgId, $asset, $bucket));
    }

    public function rawBalance(int $orgId, AssetType $asset, Bucket $bucket): int
    {
        return $this->currentBalance($this->account($orgId, $asset, $bucket)->id);
    }

    /** Sum of the given buckets, in the asset's smallest unit. */
    public function rawBalanceAcross(int $orgId, AssetType $asset, Bucket ...$buckets): int
    {
        $values = array_map(
            fn (Bucket $bucket): int => $this->rawBalance($orgId, $asset, $bucket),
            $buckets === [] ? Bucket::forAsset($asset) : $buckets,
        );

        return IntMath::sum($values);
    }

    // ── reserve / release ────────────────────────────────────────────────────

    public function reserve(int $orgId, FineWeight|Rial $amount, LedgerReference $ref): LedgerEntryId
    {
        $asset = self::assetOf($amount);
        $value = $this->positiveAmount($asset, $amount);

        /** @var array{0: LedgerEntryId, 1: BalanceReserved} $result */
        $result = DB::transaction(function () use ($orgId, $asset, $value, $ref): array {
            $accounts = $this->locker->lockOrganizationAsset($orgId, $asset);
            $available = $this->locker->require($accounts, $orgId, $asset, Bucket::AVAILABLE);
            $reserved = $this->locker->require($accounts, $orgId, $asset, Bucket::RESERVED);

            // Read under the lock. This is the whole defence against §3.10's
            // double-spend: the second transaction blocks here and then sees
            // the already-decremented balance.
            $availableNow = $this->lockBalance($available->id)->balance;

            if ($availableNow < $value) {
                throw new InsufficientBalanceException(
                    required: $value,
                    available: $availableNow,
                    asset: $asset->value,
                    accountId: $available->id,
                );
            }

            $group = TransactionGroup::generate();
            $description = 'Reserve for '.$ref->key();

            $out = $this->writeEntry($available, -$value, EntryType::RESERVE, $group, $ref, $description);
            $in = $this->writeEntry($reserved, $value, EntryType::RESERVE, $group, $ref, $description);

            $this->assertGroupBalances($group);

            return [
                $in->entryId(),
                new BalanceReserved(
                    organizationId: $orgId,
                    assetType: $asset->value,
                    amount: $value,
                    reservationEntryId: $in->id,
                    transactionGroup: $group->value,
                    referenceType: $ref->type,
                    referenceId: $ref->id,
                    availableAfter: $out->balance_after,
                ),
            ];
        }, 3);

        event($result[1]);

        return $result[0];
    }

    public function release(LedgerEntryId $reservationId, FineWeight|Rial|null $partialAmount = null): void
    {
        /** @var ReservationReleased $event */
        $event = DB::transaction(function () use ($reservationId, $partialAmount): ReservationReleased {
            $reservation = $this->findEntry($reservationId);

            if ($reservation->entry_type !== EntryType::RESERVE->value || $reservation->amount <= 0) {
                throw new InvalidReleaseException(
                    $reservationId->value,
                    'entry is not the credit leg of a RESERVE',
                );
            }

            $asset = $reservation->asset_type;
            $orgId = $reservation->organization_id;

            $accounts = $this->locker->lockOrganizationAsset($orgId, $asset);
            $reserved = $this->locker->require($accounts, $orgId, $asset, Bucket::RESERVED);
            $available = $this->locker->require($accounts, $orgId, $asset, Bucket::AVAILABLE);

            $outstanding = $this->outstandingReservation($reservation);

            $value = $partialAmount === null
                ? $outstanding
                : $this->positiveAmount($asset, $partialAmount);

            if ($outstanding <= 0) {
                throw new InvalidReleaseException(
                    $reservationId->value,
                    'reservation is already fully released',
                    $value,
                    $outstanding,
                );
            }

            if ($value > $outstanding) {
                throw new InvalidReleaseException(
                    $reservationId->value,
                    'requested amount exceeds the outstanding reservation',
                    $value,
                    $outstanding,
                );
            }

            $group = TransactionGroup::generate();
            $ref = $reservation->reference();
            $description = 'Release of reservation #'.$reservation->id;
            $metadata = ['reservation_entry_id' => $reservation->id];

            $out = $this->writeEntry($reserved, -$value, EntryType::RELEASE, $group, $ref, $description, $metadata);
            $this->writeEntry($available, $value, EntryType::RELEASE, $group, $ref, $description, $metadata);

            $this->assertGroupBalances($group);

            return new ReservationReleased(
                organizationId: $orgId,
                assetType: $asset->value,
                amount: $value,
                reservationEntryId: $reservation->id,
                releaseEntryId: $out->id,
                transactionGroup: $group->value,
                partial: $value < $outstanding,
                stillReserved: $outstanding - $value,
            );
        }, 3);

        event($event);
    }

    /**
     * How much of a reservation has not been released yet.
     *
     * Capped by the RESERVED bucket's current balance, because a reservation
     * can also be consumed by a SETTLEMENT_LOCK moving it on to IN_SETTLEMENT
     * rather than by a RELEASE.
     */
    private function outstandingReservation(LedgerEntryModel $reservation): int
    {
        $releasedRaw = DB::table('ledger_entries')
            ->where('account_id', $reservation->account_id)
            ->where('entry_type', EntryType::RELEASE->value)
            ->whereRaw(
                "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.reservation_entry_id')) AS UNSIGNED) = ?",
                [$reservation->id],
            )
            ->sum('amount');

        // Release legs on the RESERVED account are negative.
        $released = abs((int) $releasedRaw);
        $outstanding = $reservation->amount - $released;

        return max(0, min($outstanding, $this->currentBalance($reservation->account_id)));
    }

    // ── bucket movement ──────────────────────────────────────────────────────

    public function moveBucket(
        int $orgId,
        Bucket $from,
        Bucket $to,
        FineWeight|Rial $amount,
        LedgerReference $ref,
        ?EntryType $type = null,
    ): TransactionGroup {
        if ($from === $to) {
            throw new InvalidArgumentException('moveBucket source and destination buckets are the same');
        }

        $asset = self::assetOf($amount);
        $value = $this->positiveAmount($asset, $amount);
        $entryType = $type ?? self::transitionType($from, $to);

        return DB::transaction(function () use ($orgId, $asset, $from, $to, $value, $ref, $entryType): TransactionGroup {
            $accounts = $this->locker->lockOrganizationAsset($orgId, $asset);
            $source = $this->locker->require($accounts, $orgId, $asset, $from);
            $target = $this->locker->require($accounts, $orgId, $asset, $to);

            $group = TransactionGroup::generate();
            $description = sprintf('%s → %s for %s', $from->value, $to->value, $ref->key());

            $this->writeEntry($source, -$value, $entryType, $group, $ref, $description);
            $this->writeEntry($target, $value, $entryType, $group, $ref, $description);

            $this->assertGroupBalances($group);

            return $group;
        }, 3);
    }

    // ── transfer ─────────────────────────────────────────────────────────────

    public function transfer(
        int $fromOrgId,
        int $toOrgId,
        FineWeight|Rial $amount,
        LedgerReference $ref,
        Bucket $fromBucket = Bucket::AVAILABLE,
        Bucket $toBucket = Bucket::AVAILABLE,
        ?EntryType $debitType = null,
        ?EntryType $creditType = null,
    ): TransferResult {
        if ($fromOrgId === $toOrgId) {
            throw new SelfTransferException($fromOrgId);
        }

        $asset = self::assetOf($amount);
        $value = $this->positiveAmount($asset, $amount);
        $debitType ??= $asset === AssetType::GOLD ? EntryType::TRADE_SELL_GOLD : EntryType::TRADE_BUY_CASH;
        $creditType ??= $asset === AssetType::GOLD ? EntryType::TRADE_BUY_GOLD : EntryType::TRADE_SELL_CASH;

        $result = DB::transaction(function () use (
            $fromOrgId, $toOrgId, $asset, $value, $ref, $fromBucket, $toBucket, $debitType, $creditType
        ): TransferResult {
            // Both organisations locked in one statement, ordered by account id
            // across the whole set — see AccountLocker.
            $byOrg = $this->locker->lockOrganizations($asset, $fromOrgId, $toOrgId);

            $sourceAccounts = $byOrg->get($fromOrgId) ?? collect();
            $targetAccounts = $byOrg->get($toOrgId) ?? collect();

            $source = $this->locker->require($sourceAccounts, $fromOrgId, $asset, $fromBucket);
            $target = $this->locker->require($targetAccounts, $toOrgId, $asset, $toBucket);

            $group = TransactionGroup::generate();
            $description = 'Transfer for '.$ref->key();

            $out = $this->writeEntry($source, -$value, $debitType, $group, $ref, $description);
            $in = $this->writeEntry($target, $value, $creditType, $group, $ref, $description);

            $this->assertGroupBalances($group);

            return new TransferResult(
                group: $group,
                debitEntryId: $out->entryId(),
                creditEntryId: $in->entryId(),
                fromOrganizationId: $fromOrgId,
                toOrganizationId: $toOrgId,
                amount: $value,
                assetType: $asset->value,
            );
        }, 3);

        event(new TransferCompleted(
            fromOrganizationId: $fromOrgId,
            toOrganizationId: $toOrgId,
            assetType: $asset->value,
            amount: $value,
            fromBucket: $fromBucket->value,
            toBucket: $toBucket->value,
            transactionGroup: $result->group->value,
            debitEntryId: $result->debitEntryId->value,
            creditEntryId: $result->creditEntryId->value,
            referenceType: $ref->type,
            referenceId: $ref->id,
        ));

        return $result;
    }

    // ── credit / debit against a system account ──────────────────────────────

    public function credit(
        int $orgId,
        Bucket $bucket,
        FineWeight|Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId {
        return $this->oneSided($orgId, $bucket, $amount, $type, $ref, 1, $counterparty, $description);
    }

    public function debit(
        int $orgId,
        Bucket $bucket,
        FineWeight|Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        ?SystemAccountCode $counterparty = null,
        ?string $description = null,
    ): LedgerEntryId {
        return $this->oneSided($orgId, $bucket, $amount, $type, $ref, -1, $counterparty, $description);
    }

    /**
     * A member-facing credit or debit is never really one-sided: the opposite
     * leg lands on a system account so Σ(entries per asset) over the whole
     * system stays zero (invariant I4). Which system account is decided by
     * EntryType::systemCounterpart() unless the caller names one.
     */
    private function oneSided(
        int $orgId,
        Bucket $bucket,
        FineWeight|Rial $amount,
        EntryType $type,
        LedgerReference $ref,
        int $sign,
        ?SystemAccountCode $counterparty,
        ?string $description,
    ): LedgerEntryId {
        $asset = self::assetOf($amount);
        $value = $this->positiveAmount($asset, $amount) * $sign;
        $code = $counterparty ?? $type->systemCounterpart($asset, $sign);
        $counterType = self::counterLegType($type);

        return DB::transaction(function () use ($orgId, $asset, $bucket, $value, $type, $counterType, $ref, $code, $description): LedgerEntryId {
            $memberAccount = $this->account($orgId, $asset, $bucket);
            $systemAccount = $this->systemAccount($code, $asset);

            // Ascending id order across both accounts (AGENT_BRIEF rule 4).
            $locked = $this->locker->lockAccounts($memberAccount->id, $systemAccount->id);
            $memberAccount = $locked->get($memberAccount->id) ?? $memberAccount;
            $systemAccount = $locked->get($systemAccount->id) ?? $systemAccount;

            $group = TransactionGroup::generate();

            $entry = $this->writeEntry($memberAccount, $value, $type, $group, $ref, $description);
            $this->writeEntry($systemAccount, -$value, $counterType, $group, $ref, $description);

            $this->assertGroupBalances($group);

            return $entry->entryId();
        }, 3);
    }

    // ── reversal ─────────────────────────────────────────────────────────────

    public function reverse(LedgerEntryId $entryId, string $reason, int $requestedBy, int $approvedBy): LedgerEntryId
    {
        if ($reason === '') {
            throw new InvalidArgumentException('A reversal must state a reason');
        }

        if ($requestedBy === $approvedBy) {
            throw new OperationNotPermittedException(
                'A reversal must be requested and approved by two different users'
            );
        }

        /** @var array{0: LedgerEntryId, 1: EntryReversed} $result */
        $result = DB::transaction(function () use ($entryId, $reason, $requestedBy, $approvedBy): array {
            $original = $this->findEntry($entryId);

            // Invariant I8. The UNIQUE key on original_entry_id is the real
            // guarantee; this lock turns a racing duplicate into a clean error.
            $existing = LedgerReversalModel::query()
                ->where('original_entry_id', $original->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new AlreadyReversedException($original->id, $existing->reversal_entry_id);
            }

            $asset = $original->asset_type;
            $account = LedgerAccountModel::query()->findOrFail($original->account_id);

            // The counter-leg goes to SUSPENSE: reversing one leg of a balanced
            // pair would otherwise break I4 system-wide. Operations then reverse
            // the sibling leg too, which returns SUSPENSE to zero.
            $suspenseCode = ($account->system_account_code === SystemAccountCode::SUSPENSE->value)
                ? SystemAccountCode::CLEARING
                : SystemAccountCode::SUSPENSE;
            $suspense = $this->systemAccount($suspenseCode, $asset);

            $locked = $this->locker->lockAccounts($account->id, $suspense->id);
            $account = $locked->get($account->id) ?? $account;
            $suspense = $locked->get($suspense->id) ?? $suspense;

            $group = TransactionGroup::generate();
            $ref = $original->reference();
            $description = "Reversal: {$reason}";
            $metadata = ['reverses_entry_id' => $original->id];

            $reversal = $this->writeEntry(
                $account,
                -$original->amount,
                EntryType::REVERSAL,
                $group,
                $ref,
                $description,
                $metadata,
            );

            $this->writeEntry(
                $suspense,
                $original->amount,
                EntryType::REVERSAL,
                $group,
                $ref,
                $description,
                $metadata,
            );

            $this->assertGroupBalances($group);

            // Option الف of §3.6: the link is a new row, the original is untouched.
            LedgerReversalModel::query()->create([
                'original_entry_id' => $original->id,
                'reversal_entry_id' => $reversal->id,
                'reason' => $reason,
                'requested_by_user_id' => $requestedBy,
                'approved_by_user_id' => $approvedBy,
            ]);

            return [
                $reversal->entryId(),
                new EntryReversed(
                    originalEntryId: $original->id,
                    reversalEntryId: $reversal->id,
                    accountId: $account->id,
                    organizationId: $account->organization_id,
                    assetType: $asset->value,
                    reversedAmount: -$original->amount,
                    reason: $reason,
                    requestedByUserId: $requestedBy,
                    approvedByUserId: $approvedBy,
                    transactionGroup: $group->value,
                ),
            ];
        }, 3);

        event($result[1]);

        return $result[0];
    }

    // ── maintenance ──────────────────────────────────────────────────────────

    public function rebuildBalance(int $orgId, AssetType $asset, Bucket $bucket): int
    {
        $accountId = $this->account($orgId, $asset, $bucket)->id;

        return $this->rebuildAccountBalance($accountId);
    }

    /** Recompute one account's cached balance straight from ledger_entries. */
    public function rebuildAccountBalance(int $accountId): int
    {
        return DB::transaction(function () use ($accountId): int {
            $this->locker->lockAccounts($accountId);
            $balance = $this->lockBalance($accountId);

            $row = DB::table('ledger_entries')
                ->where('account_id', $accountId)
                ->selectRaw('COALESCE(SUM(amount), 0) AS total, COUNT(*) AS entries, MAX(id) AS last_id')
                ->first();

            $computed = (int) ($row->total ?? 0);

            $balance->forceFill([
                'balance' => $computed,
                'entry_count' => (int) ($row->entries ?? 0),
                'last_entry_id' => $row->last_id === null ? null : (int) $row->last_id,
                'version' => $balance->version + 1,
            ])->save();

            return $computed;
        }, 3);
    }

    public function postGroup(callable $fn): TransactionGroup
    {
        return DB::transaction(function () use ($fn): TransactionGroup {
            $group = TransactionGroup::generate();

            $fn($this->groupWriter($group));

            $this->assertGroupBalances($group);

            return $group;
        }, 3);
    }

    /** @internal exposed for ReconciliationService and the group writer */
    public function groupWriter(TransactionGroup $group): GroupWriter
    {
        return new LedgerGroupWriter(
            $group,
            Closure::fromCallable([$this, 'writeEntry']),
            fn (int $orgId, AssetType $asset, Bucket $bucket): LedgerAccountModel => $this->lockedAccount($orgId, $asset, $bucket),
            fn (SystemAccountCode $code, AssetType $asset): LedgerAccountModel => $this->lockedSystemAccount($code, $asset),
        );
    }

    // ── the single write path ────────────────────────────────────────────────

    /**
     * Append one immutable row and move the cached balance with it.
     *
     * Private on purpose: nothing outside this class may write to the ledger,
     * and everything that reaches here has already been checked for balance,
     * locking and grouping by the calling public method.
     *
     * @param  ?array<string, mixed>  $metadata
     */
    private function writeEntry(
        LedgerAccountModel $account,
        int $amount,
        EntryType $type,
        TransactionGroup $group,
        LedgerReference $ref,
        ?string $description = null,
        ?array $metadata = null,
    ): LedgerEntryModel {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('writeEntry must run inside a database transaction');
        }

        if ($amount === 0) {
            throw new InvalidArgumentException('A ledger entry amount cannot be zero');
        }

        if (! $type->appliesTo($account->asset_type)) {
            throw new InvalidArgumentException(sprintf(
                'Entry type %s cannot be posted to a %s account',
                $type->value,
                $account->asset_type->value,
            ));
        }

        if ($account->status !== LedgerAccountModel::STATUS_ACTIVE) {
            throw new OperationNotPermittedException(
                "Ledger account {$account->id} is {$account->status}"
            );
        }

        // Serialisation point: whoever holds this row decides the next balance.
        $balance = $this->lockBalance($account->id);
        $newBalance = IntMath::add($balance->balance, $amount);

        if ($newBalance < 0 && ! $account->allows_negative) {
            throw new InsufficientBalanceException(
                required: abs($amount),
                available: $balance->balance,
                asset: $account->asset_type->value,
                accountId: $account->id,
            );
        }

        $prevHash = $balance->last_entry_id === null
            ? null
            : LedgerEntryModel::query()->whereKey($balance->last_entry_id)->value('row_hash');

        $createdAt = now()->format('Y-m-d H:i:s.u');

        $entry = new LedgerEntryModel;
        $entry->forceFill([
            'account_id' => $account->id,
            'organization_id' => $account->organization_id,
            'asset_type' => $account->asset_type->value,
            'amount' => $amount,
            'entry_type' => $type->value,
            'direction' => Direction::ofAmount($amount)->value,
            'reference_type' => $ref->type,
            'reference_id' => $ref->id,
            'transaction_group' => $group->value,
            'balance_after' => $newBalance,
            'description' => $description,
            'metadata' => $metadata,
            'created_by_user_id' => Auth::id(),
            'created_at' => $createdAt,
            'prev_hash' => $prevHash,
            'row_hash' => $this->hashChain->compute(
                $prevHash,
                $createdAt,
                $account->id,
                $amount,
                $type->value,
                $ref->key(),
                $newBalance,
            ),
        ]);
        $entry->save();

        $balance->forceFill([
            'balance' => $newBalance,
            'last_entry_id' => $entry->id,
            'entry_count' => $balance->entry_count + 1,
            'version' => $balance->version + 1,
        ])->save();

        return $entry;
    }

    /**
     * Invariant I1 — conservation of mass for one operation.
     *
     * Called at the end of every public mutating method. Worked example 7 is
     * exactly this check firing on a group whose counter-leg was forgotten.
     */
    private function assertGroupBalances(TransactionGroup $group): void
    {
        // DB::table, not Eloquent: asset_type is cast to an enum on the model
        // and enum objects cannot be used as array keys.
        $sums = DB::table('ledger_entries')
            ->where('transaction_group', $group->value)
            ->groupBy('asset_type')
            ->selectRaw('asset_type, SUM(amount) AS total')
            ->pluck('total', 'asset_type');

        foreach ($sums as $asset => $total) {
            if ((int) $total !== 0) {
                throw new UnbalancedTransactionException($group->value, (string) $asset, (int) $total);
            }
        }
    }

    // ── account and balance helpers ──────────────────────────────────────────

    private function account(int $orgId, AssetType $asset, Bucket $bucket): LedgerAccountModel
    {
        $account = LedgerAccountModel::query()
            ->where('organization_id', $orgId)
            ->where('asset_type', $asset->value)
            ->where('bucket', $bucket->value)
            ->whereNull('system_account_code')
            ->first();

        if ($account === null) {
            throw new LedgerAccountNotFoundException($orgId, $asset, $bucket);
        }

        return $account;
    }

    private function lockedAccount(int $orgId, AssetType $asset, Bucket $bucket): LedgerAccountModel
    {
        $id = $this->account($orgId, $asset, $bucket)->id;

        return $this->locker->lockAccounts($id)->get($id)
            ?? throw new LedgerAccountNotFoundException($orgId, $asset, $bucket);
    }

    private function systemAccount(SystemAccountCode $code, AssetType $asset): LedgerAccountModel
    {
        $account = LedgerAccountModel::query()
            ->where('organization_id', LedgerAccountModel::SYSTEM_ORGANIZATION_ID)
            ->where('asset_type', $asset->value)
            ->where('system_account_code', $code->value)
            ->first();

        if ($account === null) {
            throw new LedgerAccountNotFoundException(
                LedgerAccountModel::SYSTEM_ORGANIZATION_ID,
                $asset,
                Bucket::AVAILABLE,
            );
        }

        return $account;
    }

    private function lockedSystemAccount(SystemAccountCode $code, AssetType $asset): LedgerAccountModel
    {
        $id = $this->systemAccount($code, $asset)->id;

        return $this->locker->lockAccounts($id)->get($id)
            ?? throw new LedgerAccountNotFoundException(0, $asset, Bucket::AVAILABLE);
    }

    private function lockBalance(int $accountId): LedgerBalanceModel
    {
        $balance = LedgerBalanceModel::query()
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            // A ledger_balances row is created with the account; a missing one
            // means provisioning is broken, so create it rather than corrupt.
            $balance = LedgerBalanceModel::query()->create([
                'account_id' => $accountId,
                'balance' => 0,
                'entry_count' => 0,
                'version' => 0,
            ]);
        }

        return $balance;
    }

    private function currentBalance(int $accountId): int
    {
        return (int) (LedgerBalanceModel::query()
            ->where('account_id', $accountId)
            ->value('balance') ?? 0);
    }

    private function findEntry(LedgerEntryId $entryId): LedgerEntryModel
    {
        $entry = LedgerEntryModel::query()->whereKey($entryId->value)->first();

        if ($entry === null) {
            throw new LedgerEntryNotFoundException($entryId->value);
        }

        return $entry;
    }

    // ── small pure helpers ───────────────────────────────────────────────────

    public static function assetOf(FineWeight|Rial $amount): AssetType
    {
        return $amount instanceof FineWeight ? AssetType::GOLD : AssetType::RIAL;
    }

    private function positiveAmount(AssetType $asset, FineWeight|Rial $amount): int
    {
        $value = $asset->unwrap($amount);

        if ($value <= 0) {
            throw new InvalidArgumentException('Ledger operations take a strictly positive amount');
        }

        return $value;
    }

    /** Default entry type for a bucket transition (docs §3.5). */
    private static function transitionType(Bucket $from, Bucket $to): EntryType
    {
        return match (true) {
            $from === Bucket::AVAILABLE && $to === Bucket::RESERVED => EntryType::RESERVE,
            $from === Bucket::RESERVED && $to === Bucket::AVAILABLE => EntryType::RELEASE,
            $from === Bucket::RESERVED && $to === Bucket::IN_SETTLEMENT => EntryType::SETTLEMENT_LOCK,
            $from === Bucket::AVAILABLE && $to === Bucket::IN_SETTLEMENT => EntryType::SETTLEMENT_LOCK,
            $from === Bucket::IN_SETTLEMENT && $to === Bucket::AVAILABLE => EntryType::SETTLEMENT_RELEASE,
            $to === Bucket::IN_DISPUTE => EntryType::DISPUTE_HOLD,
            $from === Bucket::IN_DISPUTE => EntryType::DISPUTE_RELEASE,
            default => EntryType::MANUAL_ADJUSTMENT,
        };
    }

    /**
     * The type the system-account leg carries. Fees are the one pairing §3.4
     * names explicitly: the member is charged FEE_CHARGE, the platform records
     * FEE_INCOME (worked example 1, g5).
     */
    private static function counterLegType(EntryType $type): EntryType
    {
        return match ($type) {
            EntryType::FEE_CHARGE => EntryType::FEE_INCOME,
            EntryType::PENALTY => EntryType::PENALTY_RECEIVED,
            default => $type,
        };
    }
}
