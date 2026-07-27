<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Models;

use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\AdjustmentStatus;
use App\Modules\Admin\Domain\OffsetAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to correct the ledger by hand. Owned entirely by Admin, so an
 * Eloquent model is fine here — the Eloquent-free rule applies to the adapters
 * that read *other* modules' tables.
 *
 * @property int $id
 * @property string $reference
 * @property int $organization_id
 * @property AdjustmentAsset $asset_type
 * @property int $amount
 * @property OffsetAccount $offset_account
 * @property string $reason
 * @property AdjustmentStatus $status
 * @property int $requested_by_user_id
 * @property ?int $approved_by_user_id
 * @property ?string $transaction_group
 */
final class LedgerAdjustmentRequest extends Model
{
    /** §1.10: the reason must be long enough to be an explanation. */
    public const MIN_REASON_LENGTH = 50;

    protected $table = 'ledger_adjustment_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'asset_type' => AdjustmentAsset::class,
            'offset_account' => OffsetAccount::class,
            'status' => AdjustmentStatus::class,
            'amount' => 'integer',
            'organization_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'member_entry_id' => 'integer',
            'offset_entry_id' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === AdjustmentStatus::PENDING_APPROVAL;
    }

    /** Human-facing sign, used by the confirm dialog. */
    public function reducesBalance(): bool
    {
        return $this->amount < 0;
    }
}
