<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Drivers;

use App\Modules\Pricing\Contracts\DriverTick;
use App\Modules\Pricing\Contracts\PriceDriverInterface;
use App\Modules\Pricing\Domain\PriceType;
use Carbon\CarbonImmutable;

/**
 * Deterministic feed for tests and local development. Returns whatever it was
 * seeded with and never performs I/O — the real vendor adapters are configured
 * per deployment and are not part of this repository.
 */
final class StubPriceDriver implements PriceDriverInterface
{
    public const CODE = 'stub';

    /** @var array<string, DriverTick> */
    private array $values = [];

    public function code(): string
    {
        return self::CODE;
    }

    public function supports(PriceType $type): bool
    {
        return true;
    }

    public function fetch(PriceType $type): ?DriverTick
    {
        return $this->values[$type->value] ?? null;
    }

    public function set(PriceType $type, int $value, ?CarbonImmutable $observedAt = null, ?int $scale = null): self
    {
        $this->values[$type->value] = new DriverTick(
            $type,
            $value,
            $scale ?? $type->defaultScale(),
            $observedAt ?? CarbonImmutable::now(),
        );

        return $this;
    }

    public function clear(): self
    {
        $this->values = [];

        return $this;
    }
}
