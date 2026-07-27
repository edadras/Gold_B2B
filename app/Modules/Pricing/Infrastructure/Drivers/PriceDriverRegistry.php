<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Drivers;

use App\Modules\Pricing\Contracts\PriceDriverInterface;
use RuntimeException;

/**
 * Maps price_sources.driver onto a concrete adapter. Vendor drivers register
 * themselves from their own service provider; nothing here reaches the network.
 */
final class PriceDriverRegistry
{
    /** @var array<string, PriceDriverInterface> */
    private array $drivers = [];

    /** @param iterable<PriceDriverInterface> $drivers */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(PriceDriverInterface $driver): self
    {
        $this->drivers[$driver->code()] = $driver;

        return $this;
    }

    public function has(string $code): bool
    {
        return isset($this->drivers[$code]);
    }

    public function get(string $code): PriceDriverInterface
    {
        if (! isset($this->drivers[$code])) {
            throw new RuntimeException("No price driver registered for code [{$code}]");
        }

        return $this->drivers[$code];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->drivers);
    }
}
