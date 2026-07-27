<?php

declare(strict_types=1);

namespace App\Modules\Risk\Database\Factories;

use App\Modules\Risk\Domain\RiskLevel;
use App\Modules\Risk\Infrastructure\Models\RiskProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskProfile>
 */
final class RiskProfileFactory extends Factory
{
    protected $model = RiskProfile::class;

    public function definition(): array
    {
        $defaults = RiskLevel::MEDIUM->defaults();

        return array_merge($defaults, [
            'organization_id' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'risk_level' => RiskLevel::MEDIUM->value,
            'credit_score' => 600,
            'collateral_value_rial' => 0,
            'is_trading_allowed' => true,
            'restriction_reason' => null,
        ]);
    }

    public function level(RiskLevel $level): self
    {
        return $this->state(fn (): array => array_merge($level->defaults(), [
            'risk_level' => $level->value,
            'is_trading_allowed' => $level->canTrade(),
        ]));
    }

    public function restricted(string $reason = 'COMPLIANCE_REVIEW'): self
    {
        return $this->state(fn (): array => [
            'is_trading_allowed' => false,
            'restriction_reason' => $reason,
        ]);
    }
}
