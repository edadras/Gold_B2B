<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $journal_entry_id
 * @property int $organization_id
 * @property string $account_code
 * @property string $line_set
 * @property ?int $counterparty_org_id
 * @property int $debit_rial
 * @property int $credit_rial
 * @property int $debit_fine_mg
 * @property int $credit_fine_mg
 * @property int $line_no
 */
final class JournalLineModel extends Model
{
    protected $table = 'journal_lines';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'journal_entry_id' => 'int',
        'organization_id' => 'int',
        'counterparty_org_id' => 'int',
        'debit_rial' => 'int',
        'credit_rial' => 'int',
        'debit_fine_mg' => 'int',
        'credit_fine_mg' => 'int',
        'line_no' => 'int',
    ];
}
