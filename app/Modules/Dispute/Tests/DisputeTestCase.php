<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests;

use App\Modules\Dispute\Contracts\DisputeHoldPort;
use App\Modules\Dispute\Contracts\TradeParties;
use App\Modules\Dispute\Contracts\TradePartiesProvider;
use App\Modules\Dispute\Tests\Support\RecordingHoldPort;
use App\Modules\Dispute\Tests\Support\StubTradePartiesProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base test case for the Dispute module.
 *
 * The provider is registered here rather than relied upon from
 * bootstrap/providers.php: that file belongs to the coordinator and is rewritten
 * by several agents while the platform is assembled, so a module's tests must
 * not depend on being listed in it at the moment they happen to run.
 *
 * The schema is likewise separated from the shared `goldb2b_test`, which every
 * agent's `migrate:fresh` drops and recreates.
 */
abstract class DisputeTestCase extends TestCase
{
    use RefreshDatabase;

    protected RecordingHoldPort $holds;

    protected StubTradePartiesProvider $trades;

    protected function setUp(): void
    {
        parent::setUp();

        // Doubles for the two ports the module reaches the outside world
        // through. Both are recorders, so a test can assert on exactly what was
        // locked rather than on a side effect two modules away.
        $this->holds = new RecordingHoldPort;
        $this->trades = new StubTradePartiesProvider;

        $this->app->instance(DisputeHoldPort::class, $this->holds);
        $this->app->instance(TradePartiesProvider::class, $this->trades);
    }

    /** The trade from the §13.4 worked example: 500 g fine at عیار ۹۹۵. */
    protected function documentedTrade(
        int $tradeId = 88231,
        int $buyerOrgId = 184,
        int $sellerOrgId = 209,
    ): TradeParties {
        $trade = new TradeParties(
            tradeId: $tradeId,
            buyerOrgId: $buyerOrgId,
            sellerOrgId: $sellerOrgId,
            fineMg: 500_000,
            purityX10k: 9_950,
            pricePerFineGram: 78_480_000,
            grossRial: 39_240_000_000,
        );

        $this->trades->add($trade);

        return $trade;
    }
}
