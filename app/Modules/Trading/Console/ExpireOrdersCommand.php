<?php

declare(strict_types=1);

namespace App\Modules\Trading\Console;

use App\Modules\Trading\Application\OrderExpiryService;
use Illuminate\Console\Command;

/**
 * `orders:expire` — retire GTD orders whose deadline has passed.
 *
 * Runs every minute. DAY orders are not its business: those die with the
 * session, in `market:close`.
 */
final class ExpireOrdersCommand extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Expire good-till-date orders past their deadline and release their reservations';

    public function handle(OrderExpiryService $orders): int
    {
        $expired = $orders->expireDueOrders();

        $this->info(sprintf('Expired %d order(s).', $expired));

        return self::SUCCESS;
    }
}
