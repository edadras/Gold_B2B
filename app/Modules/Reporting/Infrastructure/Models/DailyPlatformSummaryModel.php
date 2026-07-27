<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $summary_date
 * @property int $unreconciled_orgs
 * @property bool $is_reconciled
 */
final class DailyPlatformSummaryModel extends Model
{
    protected $table = 'daily_platform_summary';

    protected $primaryKey = 'summary_date';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'active_org_count' => 'int',
        'trading_org_count' => 'int',
        'gold_traded_mg' => 'int',
        'rial_traded' => 'int',
        'trade_count' => 'int',
        'gold_deposited_mg' => 'int',
        'gold_withdrawn_mg' => 'int',
        'gold_held_mg' => 'int',
        'fee_income_rial' => 'int',
        'settlement_count' => 'int',
        'default_count' => 'int',
        'dispute_opened_count' => 'int',
        'unreconciled_orgs' => 'int',
        'is_reconciled' => 'bool',
        'computed_at' => 'datetime',
    ];
}
