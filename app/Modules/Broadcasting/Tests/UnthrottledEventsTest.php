<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests;

use App\Modules\Broadcasting\Contracts\ThrottledBroadcast;
use App\Modules\Broadcasting\Domain\BroadcastEventName;
use App\Modules\Broadcasting\Events\BalanceUpdated;
use App\Modules\Broadcasting\Events\NotificationDelivered;
use App\Modules\Broadcasting\Events\OrderUpdated;
use App\Modules\Broadcasting\Events\PrivateTradeExecuted;
use App\Modules\Broadcasting\Events\PublicTradeExecuted;
use App\Modules\Broadcasting\Events\QuoteUpdated;
use App\Modules\Broadcasting\Events\SettlementStatusChanged;
use App\Modules\Broadcasting\Listeners\BroadcastBalance;
use App\Modules\Broadcasting\Listeners\BroadcastTrade;
use App\Modules\Broadcasting\Tests\Doubles\BalanceReserved;
use App\Modules\Broadcasting\Tests\Doubles\TradeExecuted;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * §3.5: «trade.executed — بدون throttle (هر معامله)» and «balance.updated —
 * بدون throttle (مهم است)».
 *
 * These are the events a trader cannot afford to miss, so the guarantee is
 * structural rather than configured: BroadcastGateway drops a frame only when
 * it implements ThrottledBroadcast, and these classes do not. The first test
 * below states that as an invariant over the class list; the rest prove it
 * behaviourally, by pushing fifty of them through in a burst that would leave
 * a depth feed with one frame.
 */
final class UnthrottledEventsTest extends BroadcastingTestCase
{
    /** @var list<class-string> */
    private const NEVER_THROTTLED = [
        PublicTradeExecuted::class,
        PrivateTradeExecuted::class,
        BalanceUpdated::class,
        OrderUpdated::class,
        SettlementStatusChanged::class,
        NotificationDelivered::class,
    ];

    #[Test]
    public function the_events_that_must_never_be_dropped_are_not_droppable(): void
    {
        foreach (self::NEVER_THROTTLED as $class) {
            self::assertFalse(
                is_subclass_of($class, ThrottledBroadcast::class),
                $class.' must not be throttleable — §3.5 lists it as بدون throttle',
            );
        }
    }

    #[Test]
    public function the_throttle_itself_refuses_to_rate_limit_them(): void
    {
        $throttle = $this->useArrayThrottle();
        $throttle->freezeAt(1_700_000_000_000);

        for ($i = 0; $i < 50; $i++) {
            self::assertTrue(
                $throttle->allow(BroadcastEventName::TRADE_EXECUTED, 'GOLD-995-T0'),
                'trade.executed has no ceiling, even inside one frozen millisecond',
            );
            self::assertTrue(
                $throttle->allow(BroadcastEventName::BALANCE_UPDATED, 'ORG-1'),
                'balance.updated has no ceiling either',
            );
        }
    }

    #[Test]
    public function fifty_trades_in_one_instant_produce_fifty_public_prints(): void
    {
        config()->set('goldb2b.broadcasting.instrument_symbols', [7 => 'GOLD-995-T0']);

        $this->useArrayThrottle()->freezeAt(1_700_000_000_000);

        Event::fake();

        $listener = $this->app->make(BroadcastTrade::class);

        for ($i = 1; $i <= 50; $i++) {
            $listener->handle(new TradeExecuted(
                tradeId: $i,
                tradeCode: sprintf('TRD-%08d', $i),
                instrumentId: 7,
                buyerOrganizationId: 11,
                sellerOrganizationId: 22,
                fineWeightMg: 100_000,
                pricePerGramRial: 78_480_000 + $i,
                grossAmountRial: 7_848_000_000,
                buyerFeeRial: 11_772_000,
                sellerFeeRial: 11_772_000,
                buyerNetRial: 7_859_772_000,
                sellerNetRial: 7_836_228_000,
                makerSide: 'SELL',
                executedAt: '2026-07-27T09:15:33.480Z',
            ));
        }

        Event::assertDispatchedTimes(PublicTradeExecuted::class, 50);

        // Two per trade: one for the buyer, one for the seller.
        Event::assertDispatchedTimes(PrivateTradeExecuted::class, 100);
    }

    /**
     * The control case, in the same test file so the contrast is unmissable:
     * the quote refresh that rides along with each of those fifty trades IS
     * throttled, and collapses to a single frame.
     */
    #[Test]
    public function the_quote_riding_along_with_those_trades_is_throttled(): void
    {
        config()->set('goldb2b.broadcasting.instrument_symbols', [7 => 'GOLD-995-T0']);

        $this->useArrayThrottle()->freezeAt(1_700_000_000_000);

        Event::fake();

        $listener = $this->app->make(BroadcastTrade::class);

        for ($i = 1; $i <= 50; $i++) {
            $listener->handle(new TradeExecuted(
                tradeId: $i,
                tradeCode: sprintf('TRD-%08d', $i),
                instrumentId: 7,
                buyerOrganizationId: 11,
                sellerOrganizationId: 22,
                fineWeightMg: 100_000,
                pricePerGramRial: 78_480_000 + $i,
                grossAmountRial: 7_848_000_000,
                buyerFeeRial: 11_772_000,
                sellerFeeRial: 11_772_000,
                buyerNetRial: 7_859_772_000,
                sellerNetRial: 7_836_228_000,
                makerSide: 'SELL',
                executedAt: '2026-07-27T09:15:33.480Z',
            ));
        }

        Event::assertDispatchedTimes(QuoteUpdated::class, 1);
    }

    #[Test]
    public function fifty_balance_movements_produce_fifty_frames(): void
    {
        $this->useArrayThrottle()->freezeAt(1_700_000_000_000);

        Event::fake();

        $listener = $this->app->make(BroadcastBalance::class);

        for ($i = 1; $i <= 50; $i++) {
            $listener->handle(new BalanceReserved(
                organizationId: 184,
                assetType: 'GOLD',
                amount: 1_000 * $i,
                reservationEntryId: $i,
                transactionGroup: 'grp-'.$i,
                referenceType: 'ORDER',
                referenceId: 44_120 + $i,
                availableAfter: 947_320 - $i,
            ));
        }

        Event::assertDispatchedTimes(BalanceUpdated::class, 50);
    }
}
