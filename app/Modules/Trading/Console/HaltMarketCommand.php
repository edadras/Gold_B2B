<?php

declare(strict_types=1);

namespace App\Modules\Trading\Console;

use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\MarketSessionService;
use App\Modules\Trading\Events\MarketPaused;
use Illuminate\Console\Command;

/**
 * `market:halt` — the manual emergency stop (§4.8).
 *
 * Same PAUSED state the circuit breaker uses, so cancellation stays available
 * to members while new orders do not. An operator halt has no automatic resume
 * time unless one is given: someone has to decide the market is safe again.
 */
final class HaltMarketCommand extends Command
{
    protected $signature = 'market:halt
        {instrument?* : instrument codes; all active instruments when omitted}
        {--reason= : why the market is being halted}
        {--minutes= : auto-resume after this many minutes}';

    protected $description = 'Pause trading for one or more instruments';

    public function handle(MarketSessionService $sessions, InstrumentRepository $instruments): int
    {
        $reasonOption = $this->option('reason');
        $reason = is_string($reasonOption) && $reasonOption !== ''
            ? $reasonOption
            : 'Manual halt by operator';

        $minutesOption = $this->option('minutes');
        $minutes = is_string($minutesOption) && $minutesOption !== '' ? (int) $minutesOption : null;

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
            $session = $sessions->pause($instrument->id, MarketPaused::REASON_MANUAL, $reason, $minutes);

            $this->line(sprintf(
                '%s → %s',
                $instrument->code,
                $session?->status->value ?? 'NO SESSION',
            ));
        }

        return self::SUCCESS;
    }
}
