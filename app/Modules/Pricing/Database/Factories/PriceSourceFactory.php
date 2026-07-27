<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Database\Factories;

use App\Modules\Pricing\Domain\PriceType;
use App\Modules\Pricing\Domain\SourceStatus;
use App\Modules\Pricing\Domain\SourceType;
use App\Modules\Pricing\Infrastructure\Drivers\StubPriceDriver;
use App\Modules\Pricing\Infrastructure\Models\PriceSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceSource>
 */
final class PriceSourceFactory extends Factory
{
    protected $model = PriceSource::class;

    public function definition(): array
    {
        return [
            'code' => 'src_'.$this->faker->unique()->numerify('######'),
            'name' => 'Test source',
            'type' => SourceType::OUNCE->value,
            'price_type' => PriceType::OUNCE_USD->value,
            'priority' => 1,
            'driver' => StubPriceDriver::CODE,
            'endpoint' => null,
            'max_staleness_s' => 300,
            'max_deviation_bps' => 500,
            'min_sane_value' => 1,
            'max_sane_value' => PriceType::OUNCE_USD->defaultMaxSaneValue(),
            'status' => SourceStatus::ACTIVE->value,
            'is_enabled' => true,
        ];
    }

    public function ofType(PriceType $type): self
    {
        return $this->state(fn (): array => [
            'price_type' => $type->value,
            'type' => match ($type) {
                PriceType::OUNCE_USD => SourceType::OUNCE->value,
                PriceType::USD_IRR => SourceType::FX->value,
                PriceType::MESGHAL_IRR => SourceType::MESGHAL->value,
                PriceType::FINE_GRAM_IRR => SourceType::MESGHAL->value,
            },
            'max_sane_value' => $type->defaultMaxSaneValue(),
        ]);
    }

    public function manual(): self
    {
        return $this->state(fn (): array => [
            'type' => SourceType::MANUAL->value,
            'driver' => 'manual',
            'priority' => 99,
        ]);
    }

    public function priority(int $priority): self
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function status(SourceStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }
}
