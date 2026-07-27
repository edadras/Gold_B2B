<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Drivers;

use App\Modules\Pricing\Contracts\DriverTick;
use App\Modules\Pricing\Contracts\PriceDriverInterface;
use App\Modules\Pricing\Domain\PriceType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * MANUAL mode from docs/03-domain/07-pricing.md §7.4: when no automatic feed is
 * healthy an operator types the price in, and it is served from here until it
 * ages out. `submit()` is what the admin controller calls.
 */
final class ManualPriceDriver implements PriceDriverInterface
{
    public const CODE = 'manual';

    public function __construct(private readonly CacheRepository $cache) {}

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
        /** @var array{value:int, scale:int, observed_at:string}|null $entry */
        $entry = $this->cache->get($this->key($type));

        if ($entry === null) {
            return null;
        }

        return new DriverTick(
            $type,
            (int) $entry['value'],
            (int) $entry['scale'],
            CarbonImmutable::parse($entry['observed_at']),
        );
    }

    /** Operator-entered value; kept for twice the staleness window. */
    public function submit(PriceType $type, int $value, ?int $scale = null, ?CarbonImmutable $observedAt = null): void
    {
        $ttl = 2 * (int) config('goldb2b.pricing.max_staleness_seconds', 300);

        $this->cache->put($this->key($type), [
            'value' => $value,
            'scale' => $scale ?? $type->defaultScale(),
            'observed_at' => ($observedAt ?? CarbonImmutable::now())->toIso8601String(),
        ], $ttl);
    }

    public function clear(PriceType $type): void
    {
        $this->cache->forget($this->key($type));
    }

    private function key(PriceType $type): string
    {
        return "pricing:manual:{$type->value}";
    }
}
