<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link row implementing option الف of docs/03-domain/03-ledger.md §3.6 — it
 * records that one entry reverses another without ever touching the original.
 *
 * @property int $id
 * @property int $original_entry_id
 * @property int $reversal_entry_id
 * @property string $reason
 * @property int $requested_by_user_id
 * @property int $approved_by_user_id
 */
final class LedgerReversalModel extends Model
{
    protected $table = 'ledger_reversals';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'original_entry_id' => 'integer',
        'reversal_entry_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function original(): BelongsTo
    {
        return $this->belongsTo(LedgerEntryModel::class, 'original_entry_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(LedgerEntryModel::class, 'reversal_entry_id');
    }
}
