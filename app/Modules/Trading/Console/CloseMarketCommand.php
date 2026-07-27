<?php

declare(strict_types=1);

namespace App\Modules\Trading\Console;

use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\MarketSessionService;
use Illuminate\Console\Command;

/**
 * `market:close` — end the session (§4.8).
 *
 * Cancelling DAY orders and releasing their reservations is part of closing,
 * not a separate chore: MarketSessionService::close() does both so the two can
 * never drift apart.
 */
final class CloseMarketCommand extends Command
{
    protected $signature = 'market:close
        {instrument?* : instrument codes; all active instruments when omitted}
        {--closing-price= : closing price in rial per fine gram}';

    protected $description = 'Close the trading session, cancelling DAY orders and keeping GTC ones';

    public function handle(MarketSessionService $sessions, InstrumentRepository $instruments): int
    {
        $closingPrice = $this->option('closing-price');
        $closingPrice = is_string($closingPrice) && $closingPrice !== '' ? (int) $closingPrice : null;

        /** @var list<string> $codes */
        $codes = (array) $this->argument('instrument');
        $targets = $codes === []
            ? $instruments->active()
            : array_values(array_filter(array_map(
                static fn (string $code) => $instruments->findByCode($code),
                $codes,
            )));

        if ($targets === []) {
            $this->warn('No matching active instrument.');

            return self::FAILURE;
        }

        foreach ($targets as $instrument) {
            $session = $sessions->close($instrument->id, $closingPrice);

            $this->line(sprintf(
                '%s → %s',
                $instrument->code,
                $session?->status->value ?? 'NO SESSION',
            ));
        }

        return self::SUCCESS;
    }
}
