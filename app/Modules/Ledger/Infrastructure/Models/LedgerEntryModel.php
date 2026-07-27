<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Direction;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\TransactionGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * APPEND-ONLY (AGENT_BRIEF rule 2).
 *
 * update() and delete() are hard-blocked at the model level; the only sanctioned
 * correction is a reversing entry plus a ledger_reversals row. The guards below
 * fire even for saveQuietly()/forceDelete() because they hook the model events
 * rather than the public methods.
 *
 * @property int $id
 * @property int $account_id
 * @property int $organization_id
 * @property AssetType $asset_type
 * @property int $amount
 * @property string $entry_type
 * @property string $direction
 * @property string $reference_type
 * @property int $reference_id
 * @property string $transaction_group
 * @property int $balance_after
 * @property ?string $prev_hash
 * @property string $row_hash
 */
final class LedgerEntryModel extends Model
{
    protected $table = 'ledger_entries';

    /** created_at is written explicitly with microsecond precision; there is no updated_at. */
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'organization_id' => 'integer',
        'asset_type' => AssetType::class,
        'amount' => 'integer',
        'reference_id' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
        'created_by_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccountModel::class, 'account_id');
    }

    public function entryId(): LedgerEntryId
    {
        return LedgerEntryId::fromInt($this->id);
    }

    public function type(): EntryType
    {
        return EntryType::from($this->entry_type);
    }

    public function directionEnum(): Direction
    {
        return Direction::from($this->direction);
    }

    public function reference(): LedgerReference
    {
        return LedgerReference::of($this->reference_type, $this->reference_id);
    }

    public function group(): TransactionGroup
    {
        return TransactionGroup::fromString($this->transaction_group);
    }

    /** created_at as the exact string the hash chain was computed over. */
    public function createdAtString(): string
    {
        return $this->getRawOriginal('created_at') ?? $this->created_at->format('Y-m-d H:i:s.u');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException(
                'ledger_entries is append-only: correct mistakes with reverse(), never UPDATE.'
            );
        });

        static::deleting(static function (): void {
            throw new LogicException(
                'ledger_entries is append-only: rows are never deleted (invariant I6).'
            );
        });
    }
}
