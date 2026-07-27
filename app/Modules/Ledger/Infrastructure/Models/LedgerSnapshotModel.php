<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily balance checkpoint (docs/03-domain/03-ledger.md §3.8).
 *
 * @property int $id
 * @property int $account_id
 * @property string $snapshot_date
 * @property int $balance
 * @property int $last_entry_id
 * @property int $entry_count
 */
final class LedgerSnapshotModel extends Model
{
    protected $table = 'ledger_snapshots';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'snapshot_date' => 'date',
        'balance' => 'integer',
        'last_entry_id' => 'integer',
        'entry_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccountModel::class, 'account_id');
    }
}
