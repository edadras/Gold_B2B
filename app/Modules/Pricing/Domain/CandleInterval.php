<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * OHLC bucket sizes. docs/03-domain/07-pricing.md §7.7.
 */
enum CandleInterval: string
{
    case M1 = '1m';
    case M5 = '5m';
    case M15 = '15m';
    case H1 = '1h';
    case D1 = '1d';

    public function seconds(): int
    {
        return match ($this) {
            self::M1 => 60,
            self::M5 => 300,
            self::M15 => 900,
            self::H1 => 3_600,
            self::D1 => 86_400,
        };
    }

    /**
     * Start of the bucket that contains $at.
     *
     * Daily buckets are anchored to the market timezone so a candle covers a
     * trading day, not a UTC day.
     */
    public function bucketStart(CarbonInterface $at): CarbonImmutable
    {
        $timezone = (string) config('goldb2b.market.timezone', 'Asia/Tehran');
        $local = CarbonImmutable::instance($at)->setTimezone($timezone);

        if ($this === self::D1) {
            return $local->startOfDay();
        }

        $secondsIntoDay = $local->hour * 3_600 + $local->minute * 60 + $local->second;
        $aligned = intdiv($secondsIntoDay, $this->seconds()) * $this->seconds();

        return $local->startOfDay()->addSeconds($aligned);
    }

    public function bucketEnd(CarbonInterface $at): CarbonImmutable
    {
        return $this->bucketStart($at)->addSeconds($this->seconds());
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
