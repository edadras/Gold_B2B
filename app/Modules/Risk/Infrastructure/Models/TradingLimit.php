<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Models;

use App\Modules\Risk\Domain\RiskLevel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property RiskLevel $risk_level
 * @property int $max_order_mg
 * @property int $max_daily_volume_mg
 * @property int $max_open_orders
 * @property int $max_open_exposure_mg
 * @property int $max_open_exposure_rial
 * @property int $unsecured_credit_mg
 * @property list<string> $allowed_settlement_types
 * @property int $max_settlement_days
 */
final class TradingLimit extends Model
{
    protected $table = 'trading_limits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'risk_level' => RiskLevel::class,
            'max_order_mg' => 'int',
            'max_daily_volume_mg' => 'int',
            'max_open_orders' => 'int',
            'max_open_exposure_mg' => 'int',
            'max_open_exposure_rial' => 'int',
            'unsecured_credit_mg' => 'int',
            'allowed_settlement_types' => 'array',
            'max_settlement_days' => 'int',
        ];
    }

    /** Falls back to the enum defaults when the table has not been seeded. */
    public static function defaultsFor(RiskLevel $level): array
    {
        /** @var self|null $row */
        $row = self::query()->where('risk_level', $level->value)->first();

        if ($row === null) {
            return $level->defaults();
        }

        return [
            'max_order_mg' => $row->max_order_mg,
            'max_daily_volume_mg' => $row->max_daily_volume_mg,
            'max_open_orders' => $row->max_open_orders,
            'max_open_exposure_mg' => $row->max_open_exposure_mg,
            'max_open_exposure_rial' => $row->max_open_exposure_rial,
            'unsecured_credit_mg' => $row->unsecured_credit_mg,
            'allowed_settlement_types' => $row->allowed_settlement_types,
            'max_settlement_days' => $row->max_settlement_days,
        ];
    }
}
