<?php

declare(strict_types=1);

namespace App\Modules\Trading\Console;

use App\Modules\Trading\Application\OtcService;
use App\Modules\Trading\Application\RfqService;
use Illuminate\Console\Command;

/**
 * `rfq:expire-stale` — close out RFQs and OTC offers past their window.
 *
 * Both channels are time-boxed (fifteen minutes for an RFQ, thirty for an OTC
 * offer by default) and both leave something behind if nobody sweeps them: an
 * RFQ leaves quotes pending, an OTC offer leaves the initiator's gold or rial
 * locked. One command handles both so the two can never be scheduled apart.
 */
final class ExpireStaleRfqsCommand extends Command
{
    protected $signature = 'rfq:expire-stale {--skip-otc : leave OTC offers alone}';

    protected $description = 'Expire RFQs (and OTC offers) whose window has closed, releasing any locks';

    public function handle(RfqService $rfqs, OtcService $otc): int
    {
        $expiredRfqs = $rfqs->expireStale();
        $this->info(sprintf('Expired %d RFQ(s).', $expiredRfqs));

        if (! $this->option('skip-otc')) {
            $expiredOffers = $otc->expireStale();
            $this->info(sprintf('Expired %d OTC offer(s).', $expiredOffers));
        }

        return self::SUCCESS;
    }
}
