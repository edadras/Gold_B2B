<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Models;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Settlement\Domain\NettingBatchStatus;
use App\Modules\Settlement\Domain\NettingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $batch_code
 * @property NettingBatchStatus $status
 * @property NettingType $netting_type
 * @property AssetType $asset_type
 * @property int $participant_count
 * @property int $gross_transfer_count
 * @property int $net_transfer_count
 * @property int $gross_volume
 * @property int $net_volume
 */
final class NettingBatchModel extends Model
{
    protected $table = 'netting_batches';

    protected $guarded = [];

    protected $casts = [
        'status' => NettingBatchStatus::class,
        'netting_type' => NettingType::class,
        'asset_type' => AssetType::class,
        'batch_date' => 'date',
        'participant_count' => 'int',
        'gross_transfer_count' => 'int',
        'net_transfer_count' => 'int',
        'gross_volume' => 'int',
        'net_volume' => 'int',
        'proposed_by_user_id' => 'int',
        'proposed_at' => 'datetime',
        'accept_deadline_at' => 'datetime',
        'executed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return HasMany<NettingPositionModel, self> */
    public function positions(): HasMany
    {
        return $this->hasMany(NettingPositionModel::class, 'batch_id');
    }

    /** Invariant N3, restated on the model for readability at call sites. */
    public function reducesTransfers(): bool
    {
        return $this->net_transfer_count <= $this->gross_transfer_count;
    }
}
