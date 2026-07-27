<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\MetalType;
use App\Modules\Ledger\Domain\SystemAccountCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $organization_id
 * @property AssetType $asset_type
 * @property ?MetalType $metal_type
 * @property Bucket $bucket
 * @property ?string $system_account_code
 * @property bool $allows_negative
 * @property string $status
 */
final class LedgerAccountModel extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_FROZEN = 'FROZEN';

    public const STATUS_CLOSED = 'CLOSED';

    /** organization_id of the system accounts (docs §3.3). */
    public const SYSTEM_ORGANIZATION_ID = 0;

    protected $table = 'ledger_accounts';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'asset_type' => AssetType::class,
        'metal_type' => MetalType::class,
        'bucket' => Bucket::class,
        'allows_negative' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function balance(): HasOne
    {
        return $this->hasOne(LedgerBalanceModel::class, 'account_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntryModel::class, 'account_id');
    }

    public function isSystem(): bool
    {
        return $this->organization_id === self::SYSTEM_ORGANIZATION_ID;
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('organization_id', self::SYSTEM_ORGANIZATION_ID);
    }

    public function scopeMember(Builder $query): Builder
    {
        return $query->where('organization_id', '!=', self::SYSTEM_ORGANIZATION_ID);
    }

    public function scopeForCode(Builder $query, SystemAccountCode $code): Builder
    {
        return $query->where('system_account_code', $code->value);
    }

    protected static function booted(): void
    {
        // account_key is STORED GENERATED — MariaDB rejects any attempt to write it.
        static::saving(function (self $account): void {
            $account->offsetUnset('account_key');
        });
    }
}
