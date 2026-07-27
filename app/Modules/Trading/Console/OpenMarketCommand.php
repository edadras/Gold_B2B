<?php

declare(strict_types=1);

namespace App\Modules\Trading\Console;

use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\MarketSessionService;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use Illuminate\Console\Command;

/**
 * `market:open` — take instruments into PRE_OPEN or OPEN (§4.8).
 *
 * Two steps rather than one because pre-open accepts orders without matching
 * them, so the scheduler runs this twice: at 08:45 with --pre-open, and at
 * 09:00 without it.
 */
final class OpenMarketCommand extends Command
{
    protected $signature = 'market:open
        {instrument?* : instrument codes; all active instruments when omitted}
        {--pre-open : stop at PRE_OPEN, accepting orders without matching}';

    protected $description = 'Open (or pre-open) the trading session for one or more instruments';

    public function handle(MarketSessionService $sessions, InstrumentRepository $instruments): int
    {
        $targets = $this->resolve($instruments);

        if ($targets === []) {
            $this->warn('No matching active instrument.');

            return self::FAILURE;
        }

        $preOpen = (bool) $this->option('pre-open');

        foreach ($targets as $instrument) {
            $session = $preOpen
                ? $sessions->preOpen($instrument)
                : $sessions->open($instrument);

            $this->line(sprintf('%s → %s', $instrument->code, $session->status->value));
        }

        return self::SUCCESS;
    }

    /** @return list<Instrument> */
    private function resolve(InstrumentRepository $instruments): array
    {
        /** @var list<string> $codes */
        $codes = (array) $this->argument('instrument');

        if ($codes === []) {
            return $instruments->active();
        }

        $resolved = [];

        foreach ($codes as $code) {
            $instrument = $instruments->findByCode($code);

            if ($instrument === null) {
                $this->warn("Unknown instrument: {$code}");

                continue;
            }

            $resolved[] = $instrument;
        }

        return $resolved;
    }
}
