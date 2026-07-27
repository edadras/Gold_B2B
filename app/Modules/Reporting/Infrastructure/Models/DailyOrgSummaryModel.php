<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $organization_id
 * @property string $summary_date
 * @property int $gold_opening_mg
 * @property int $gold_closing_mg
 * @property bool $is_reconciled
 */
final class DailyOrgSummaryModel extends Model
{
    protected $table = 'daily_org_summary';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'int',
        'gold_opening_mg' => 'int',
        'gold_bought_mg' => 'int',
        'gold_sold_mg' => 'int',
        'gold_deposited_mg' => 'int',
        'gold_withdrawn_mg' => 'int',
        'gold_adjustment_mg' => 'int',
        'gold_closing_mg' => 'int',
        'rial_opening' => 'int',
        'rial_in' => 'int',
        'rial_out' => 'int',
        'rial_fees' => 'int',
        'rial_closing' => 'int',
        'trade_count' => 'int',
        'buy_count' => 'int',
        'sell_count' => 'int',
        'realized_pnl' => 'int',
        'avg_cost_per_gram' => 'int',
        'closing_market_value' => 'int',
        'is_reconciled' => 'bool',
        'gold_discrepancy_mg' => 'int',
        'rial_discrepancy' => 'int',
        'computed_at' => 'datetime',
    ];
}
