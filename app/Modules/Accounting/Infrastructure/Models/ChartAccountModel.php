<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property string $name
 * @property string $account_type
 * @property string $normal_balance
 * @property bool $carries_gold_quantity
 * @property string $group_code
 * @property bool $is_active
 */
final class ChartAccountModel extends Model
{
    protected $table = 'chart_of_accounts';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'int',
        'carries_gold_quantity' => 'bool',
        'is_active' => 'bool',
        'is_system' => 'bool',
    ];
}
