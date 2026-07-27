<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $period_code
 * @property string $starts_on
 * @property string $ends_on
 * @property string $status
 */
final class AccountingPeriodModel extends Model
{
    protected $table = 'accounting_periods';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'int',
        'closed_by_user_id' => 'int',
        'closed_at' => 'datetime',
        'closing_debit_rial' => 'int',
        'closing_credit_rial' => 'int',
        'closing_fine_mg' => 'int',
    ];
}
