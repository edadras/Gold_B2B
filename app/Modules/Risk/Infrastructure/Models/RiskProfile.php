<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Models;

use App\Modules\Risk\Database\Factories\RiskProfileFactory;
use App\Modules\Risk\Domain\RiskLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property RiskLevel $risk_level
 * @property int $credit_score
 * @property int $max_order_mg
 * @property int $max_daily_volume_mg
 * @property int $max_open_orders
 * @property int $max_open_exposure_mg
 * @property int $max_open_exposure_rial
 * @property int $unsecured_credit_mg
 * @property int $collateral_value_rial
 * @property list<string> $allowed_settlement_types
 * @property int $max_settlement_days
 * @property bool $is_trading_allowed
 * @property string|null $restriction_reason
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by_user_id
 * @property CarbonImmutable|null $next_review_at
 */
final class RiskProfile extends Model
{
    use HasFactory;

    protected $table = 'risk_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'risk_level' => RiskLevel::class,
            'credit_score' => 'int',
            'max_order_mg' => 'int',
            'max_daily_volume_mg' => 'int',
            'max_open_orders' => 'int',
            'max_open_exposure_mg' => 'int',
            'max_open_exposure_rial' => 'int',
            'unsecured_credit_mg' => 'int',
            'collateral_value_rial' => 'int',
            'allowed_settlement_types' => 'array',
            'max_settlement_days' => 'int',
            'is_trading_allowed' => 'bool',
            'reviewed_at' => 'immutable_datetime',
            'next_review_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): RiskProfileFactory
    {
        return RiskProfileFactory::new();
    }

    public function allowsSettlementType(string $type): bool
    {
        return in_array($type, $this->allowed_settlement_types, true);
    }
}
