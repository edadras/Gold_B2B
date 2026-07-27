<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\LedgerAdjustmentPoster;
use App\Modules\Admin\Contracts\PostedAdjustment;
use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\LedgerRowHasher;
use App\Modules\Admin\Domain\OffsetAccount;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Exceptions\UnbalancedTransactionException;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Posts an approved manual adjustment as a balanced pair of ledger entries.
 *
 * ⚠️ Duplication, and knowingly so. Ledger\Application\LedgerService is the only
 * thing that should ever write `ledger_entries`, but Ledger publishes no
 * contract for a dual-controlled manual adjustment and Admin may not import its
 * application layer. This adapter reproduces LedgerService::writeEntry() exactly
 * — the lock order, the running balance, the hash chain, the group balance
 * assertion — so the rows it leaves are indistinguishable from Ledger's own.
 * The right fix is a `Ledger\Contracts\ManualAdjustmentPoster`; when it lands,
 * bind it in AdminServiceProvider and delete this class.
 *
 * Rules honoured, in the order AGENT_BRIEF states them:
 *   · integers only, no float anywhere;
 *   · never UPDATE or DELETE a ledger entry — a correction is new rows;
 *   · nothing that leaves the process happens inside the transaction;
 *   · accounts are locked in ascending id order;
 *   · every write sits inside a transaction with a pessimistic lock.
 */
final class TableLedgerAdjustmentPoster implements LedgerAdjustmentPoster
{
    /** Mirrors Ledger\Domain\EntryType::MANUAL_ADJUSTMENT. */
    private const ENTRY_TYPE = 'MANUAL_ADJUSTMENT';

    /** Mirrors Ledger\Domain\LedgerReference::ADJUSTMENT. */
    private const REFERENCE_TYPE = 'adjustment';

    private const SYSTEM_ORGANIZATION_ID = 0;

    public function __construct(private readonly LedgerRowHasher $hasher) {}

    public function post(
        int $organizationId,
        AdjustmentAsset $asset,
        int $amount,
        OffsetAccount $offset,
        int $adjustmentRequestId,
        string $description,
        int $postedByUserId,
    ): PostedAdjustment {
        if ($amount === 0) {
            throw new OperationNotPermittedException('یک اصلاح دفتری نمی‌تواند مبلغ صفر داشته باشد.');
        }

        if (! $offset->supports($asset)) {
            throw new OperationNotPermittedException(sprintf(
                'حساب طرف مقابل %s برای دارایی %s تعریف نشده است.',
                $offset->value,
                $asset->value,
            ));
        }

        if (! $this->tablesPresent()) {
            throw new OperationNotPermittedException('جداول دفتر کل در دسترس نیستند.');
        }

        $group = (string) Str::uuid();

        /** @var PostedAdjustment $result */
        $result = DB::transaction(function () use (
            $organizationId, $asset, $amount, $offset, $adjustmentRequestId,
            $description, $postedByUserId, $group,
        ): PostedAdjustment {
            $member = $this->memberAccount($organizationId, $asset);
            $system = $this->offsetAccount($asset, $offset);

            $reference = self::REFERENCE_TYPE.':'.$adjustmentRequestId;

            // Rule 4: lock in ascending id order, always, or two adjustments
            // touching the same pair deadlock against each other.
            $ordered = [$member, $system];
            usort($ordered, static fn (object $a, object $b): int => $a->id <=> $b->id);

            $locked = [];
            foreach ($ordered as $account) {
                $locked[(int) $account->id] = $this->lockBalance((int) $account->id);
            }

            $entries = [];

            $entries['member'] = $this->writeEntry(
                $member,
                $locked[(int) $member->id],
                $amount,
                $group,
                $reference,
                $description,
                $postedByUserId,
                ['adjustment_request_id' => $adjustmentRequestId, 'leg' => 'member'],
            );

            $entries['offset'] = $this->writeEntry(
                $system,
                $locked[(int) $system->id],
                -$amount,
                $group,
                $reference,
                $description,
                $postedByUserId,
                ['adjustment_request_id' => $adjustmentRequestId, 'leg' => 'offset'],
            );

            $this->assertGroupBalances($group);

            return new PostedAdjustment(
                transactionGroup: $group,
                entryIds: [$entries['member'], $entries['offset']],
                memberAccountId: (int) $member->id,
                offsetAccountId: (int) $system->id,
            );
        }, 3);

        return $result;
    }

    private function memberAccount(int $organizationId, AdjustmentAsset $asset): object
    {
        $account = DB::table('ledger_accounts')
            ->where('organization_id', $organizationId)
            ->where('asset_type', $asset->value)
            ->where('bucket', 'AVAILABLE')
            ->whereNull('system_account_code')
            ->first();

        if ($account === null) {
            throw new OperationNotPermittedException(sprintf(
                'حساب %s سازمان %d یافت نشد.',
                $asset->value,
                $organizationId,
            ));
        }

        return $account;
    }

    private function offsetAccount(AdjustmentAsset $asset, OffsetAccount $offset): object
    {
        $account = DB::table('ledger_accounts')
            ->where('organization_id', self::SYSTEM_ORGANIZATION_ID)
            ->where('asset_type', $asset->value)
            ->where('bucket', 'AVAILABLE')
            ->where('system_account_code', $offset->value)
            ->first();

        if ($account === null) {
            throw new OperationNotPermittedException(sprintf(
                'حساب سیستمی %s برای دارایی %s وجود ندارد.',
                $offset->value,
                $asset->value,
            ));
        }

        return $account;
    }

    private function lockBalance(int $accountId): object
    {
        $balance = DB::table('ledger_balances')
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            throw new OperationNotPermittedException("ردیف مانده برای حساب {$accountId} وجود ندارد.");
        }

        return $balance;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return int id of the inserted entry
     */
    private function writeEntry(
        object $account,
        object $balance,
        int $amount,
        string $group,
        string $reference,
        string $description,
        int $postedByUserId,
        array $metadata,
    ): int {
        if ((string) $account->status !== 'ACTIVE') {
            throw new OperationNotPermittedException(
                "حساب دفتر {$account->id} در وضعیت {$account->status} است و قابل ثبت نیست."
            );
        }

        $newBalance = IntMath::add((int) $balance->balance, $amount);

        if ($newBalance < 0 && (int) $account->allows_negative === 0) {
            throw new InsufficientBalanceException(
                required: abs($amount),
                available: (int) $balance->balance,
                asset: (string) $account->asset_type,
                accountId: (int) $account->id,
            );
        }

        $prevHash = $balance->last_entry_id === null
            ? null
            : DB::table('ledger_entries')->where('id', $balance->last_entry_id)->value('row_hash');

        $prevHash = $prevHash === null ? null : (string) $prevHash;
        $createdAt = now()->format('Y-m-d H:i:s.u');

        $entryId = (int) DB::table('ledger_entries')->insertGetId([
            'account_id' => (int) $account->id,
            'organization_id' => (int) $account->organization_id,
            'asset_type' => (string) $account->asset_type,
            'amount' => $amount,
            'entry_type' => self::ENTRY_TYPE,
            'direction' => $amount > 0 ? 'CREDIT' : 'DEBIT',
            'reference_type' => self::REFERENCE_TYPE,
            'reference_id' => (int) explode(':', $reference)[1],
            'transaction_group' => $group,
            'balance_after' => $newBalance,
            'description' => $description,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_by_user_id' => $postedByUserId,
            'created_at' => $createdAt,
            'prev_hash' => $prevHash,
            'row_hash' => $this->hasher->compute(
                $prevHash,
                $createdAt,
                (int) $account->id,
                $amount,
                self::ENTRY_TYPE,
                $reference,
                $newBalance,
            ),
        ]);

        DB::table('ledger_balances')
            ->where('account_id', (int) $account->id)
            ->update([
                'balance' => $newBalance,
                'last_entry_id' => $entryId,
                'entry_count' => (int) $balance->entry_count + 1,
                'version' => (int) $balance->version + 1,
                'updated_at' => now(),
            ]);

        return $entryId;
    }

    /** Invariant I1: the pair must net to zero, or the transaction rolls back. */
    private function assertGroupBalances(string $group): void
    {
        $sums = DB::table('ledger_entries')
            ->where('transaction_group', $group)
            ->groupBy('asset_type')
            ->selectRaw('asset_type, SUM(amount) AS total')
            ->pluck('total', 'asset_type');

        foreach ($sums as $asset => $total) {
            if ((int) $total !== 0) {
                throw new UnbalancedTransactionException($group, (string) $asset, (int) $total);
            }
        }
    }

    private function tablesPresent(): bool
    {
        return Schema::hasTable('ledger_accounts')
            && Schema::hasTable('ledger_entries')
            && Schema::hasTable('ledger_balances');
    }
}
