<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\PriceSourceMode;
use App\Modules\Pricing\Domain\PriceType;
use Carbon\CarbonImmutable;

/**
 * Which price we are actually using right now, and how confident we are in it.
 */
final readonly class PriceResolution
{
    public function __construct(
        public PriceType $priceType,
        public PriceSourceMode $mode,
        public ?int $value = null,
        public int $scale = 0,
        public ?int $sourceId = null,
        public ?string $sourceCode = null,
        public ?int $tickId = null,
        public ?CarbonImmutable $observedAt = null,
        public bool $shouldHaltMarket = false,
        public ?int $secondsSinceLastPrice = null,
    ) {}

    public static function none(PriceType $type, ?int $secondsSinceLastPrice, bool $shouldHaltMarket): self
    {
        return new self(
            priceType: $type,
            mode: PriceSourceMode::NONE,
            shouldHaltMarket: $shouldHaltMarket,
            secondsSinceLastPrice: $secondsSinceLastPrice,
        );
    }

    public function hasValue(): bool
    {
        return $this->value !== null;
    }

    /** UI must show an explicit banner for manual or missing prices (§7.10). */
    public function requiresBanner(): bool
    {
        return $this->mode->requiresUserBanner();
    }
}
