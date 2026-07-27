<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rebuildable balance cache. See the migration for why this is not the source
 * of truth. Writers take this row FOR UPDATE, which is what serialises
 * concurrent operations on the same account.
 *
 * @property int $account_id
 * @property int $balance
 * @property ?int $last_entry_id
 * @property int $entry_count
 * @property int $version
 */
final class LedgerBalanceModel extends Model
{
    protected $table = 'ledger_balances';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'balance' => 'integer',
        'last_entry_id' => 'integer',
        'entry_count' => 'integer',
        'version' => 'integer',
        'updated_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccountModel::class, 'account_id');
    }
}
