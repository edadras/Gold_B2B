<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $voucher_no
 * @property string $entry_date
 * @property string $description
 * @property string $source_type
 * @property int $source_id
 * @property string $status
 * @property ?int $reverses_id
 * @property ?int $reversed_by_id
 * @property int $total_rial
 * @property int $total_fine_mg
 */
final class JournalEntryModel extends Model
{
    protected $table = 'journal_entries';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'int',
        'source_id' => 'int',
        'reverses_id' => 'int',
        'reversed_by_id' => 'int',
        'total_rial' => 'int',
        'total_fine_mg' => 'int',
        'accounting_period_id' => 'int',
        'created_by_user_id' => 'int',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @return HasMany<JournalLineModel, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLineModel::class, 'journal_entry_id');
    }
}
