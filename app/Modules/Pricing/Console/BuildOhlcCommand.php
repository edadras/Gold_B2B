<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Console;

use App\Modules\Pricing\Application\CandleService;
use App\Modules\Pricing\Domain\CandleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `php artisan pricing:build-ohlc` — scheduled every five minutes,
 * docs/03-domain/07-pricing.md §7.7.
 */
final class BuildOhlcCommand extends Command
{
    protected $signature = 'pricing:build-ohlc
        {--at= : build the buckets containing this timestamp instead of now}
        {--instrument= : restrict to one instrument}
        {--interval= : restrict to one interval code (1m, 5m, 15m, 1h, 1d)}';

    protected $description = 'Build OHLCV candles from order-book trades';

    public function handle(CandleService $candles): int
    {
        $at = is_string($this->option('at')) && $this->option('at') !== ''
            ? CarbonImmutable::parse($this->option('at'))
            : CarbonImmutable::now();

        $instrument = $this->option('instrument');
        $intervalOption = $this->option('interval');

        if ($instrument === null && $intervalOption === null) {
            $written = $candles->buildAll($at);
            $this->info("Built {$written} candle(s).");

            return self::SUCCESS;
        }

        $intervals = is_string($intervalOption) && $intervalOption !== ''
            ? [CandleInterval::from($intervalOption)]
            : CandleInterval::all();

        if ($instrument === null) {
            $this->error('--interval requires --instrument.');

            return self::INVALID;
        }

        $written = 0;

        foreach ($intervals as $interval) {
            if ($candles->build((int) $instrument, $interval, $at) !== null) {
                $written++;
            }
        }

        $this->info("Built {$written} candle(s).");

        return self::SUCCESS;
    }
}
