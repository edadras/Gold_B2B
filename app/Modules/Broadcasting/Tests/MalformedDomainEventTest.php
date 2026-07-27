<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests;

use App\Modules\Broadcasting\Domain\EventShape;
use App\Modules\Broadcasting\Events\PrivateTradeExecuted;
use App\Modules\Broadcasting\Events\PublicTradeExecuted;
use App\Modules\Broadcasting\Listeners\BroadcastBalance;
use App\Modules\Broadcasting\Listeners\BroadcastMarketStatus;
use App\Modules\Broadcasting\Listeners\BroadcastNotification;
use App\Modules\Broadcasting\Listeners\BroadcastOrderBookDepth;
use App\Modules\Broadcasting\Listeners\BroadcastOrderLifecycle;
use App\Modules\Broadcasting\Listeners\BroadcastReferencePrice;
use App\Modules\Broadcasting\Listeners\BroadcastRfqActivity;
use App\Modules\Broadcasting\Listeners\BroadcastSettlementStatus;
use App\Modules\Broadcasting\Listeners\BroadcastTrade;
use App\Modules\Broadcasting\Tests\Doubles\Malformed\EmptyEvent;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * An upstream event that changed shape must cost a broadcast, not a request.
 *
 * This module subscribes to four other modules by string class name and never
 * type-hints what arrives (see BroadcastingServiceProvider). That is what keeps
 * the dependency graph legal, and it is also what makes this test necessary:
 * without a compiler checking the payload, the only thing standing between a
 * renamed property and a fatal inside Laravel's dispatcher is
 * Broadcasting\Domain\EventShape.
 *
 * The stakes are concrete. These listeners run on the request that just wrote
 * a trade. An uncaught TypeError here does not merely lose a WebSocket frame —
 * it turns a successful fill into a 500, after the money has already moved.
 * So the contract is: degrade to silence, never throw.
 */
final class MalformedDomainEventTest extends BroadcastingTestCase
{
    /** @return array<string, array{0: class-string}> */
    public static function listeners(): array
    {
        return [
            'trade' => [BroadcastTrade::class],
            'order lifecycle' => [BroadcastOrderLifecycle::class],
            'market status' => [BroadcastMarketStatus::class],
            'reference price' => [BroadcastReferencePrice::class],
            'balance' => [BroadcastBalance::class],
            'settlement' => [BroadcastSettlementStatus::class],
            'rfq' => [BroadcastRfqActivity::class],
            'notification' => [BroadcastNotification::class],
            'depth' => [BroadcastOrderBookDepth::class],
        ];
    }

    /**
     * The degenerate case: an event with no properties at all, handed to every
     * listener in the module.
     */
    #[Test]
    #[DataProvider('listeners')]
    public function no_listener_throws_on_an_event_with_no_properties(string $listener): void
    {
        Event::fake();

        $this->app->make($listener)->handle(new EmptyEvent);

        Event::assertNothingDispatched();
    }

    /**
     * The realistic case: the right class name, the wrong contents — a field
     * renamed, a type changed, an organisation id gone.
     */
    #[Test]
    public function renamed_and_retyped_fields_produce_silence_rather_than_an_exception(): void
    {
        Event::fake();

        // Every field renamed.
        $this->app->make(BroadcastTrade::class)
            ->handle(new Doubles\Malformed\TradeExecuted(trade_id: 1, code: 'TRD-1'));

        // Numbers arrived as strings — EventShape refuses to coerce, because
        // silently turning "abc" into 0 rial is worse than not broadcasting.
        $this->app->make(BroadcastOrderLifecycle::class)
            ->handle(new Doubles\Malformed\OrderPartiallyFilled(
                orderId: '44120',
                organizationId: '184',
                filledMg: '100000',
            ));

        // No organisation on it at all, so there is no channel to put it on.
        $this->app->make(BroadcastSettlementStatus::class)
            ->handle(new Doubles\Malformed\SettlementOpened(
                settlementId: 88231,
                settlementCode: 'STL-00088231',
            ));

        // Missing the asset type.
        $this->app->make(BroadcastBalance::class)
            ->handle(new Doubles\Malformed\BalanceReserved(organizationId: 184, amount: 1_000));

        // A null price.
        $this->app->make(BroadcastReferencePrice::class)
            ->handle(new Doubles\Malformed\PriceTickAccepted(priceType: 'GOLD_18K_GRAM'));

        Event::assertNothingDispatched();
    }

    /**
     * A domain event whose class this module has never heard of — the case
     * where a producer renames its event class rather than its fields.
     */
    #[Test]
    public function an_unrecognised_event_class_is_ignored(): void
    {
        Event::fake();

        $unknown = new class
        {
            public int $organizationId = 184;

            public string $assetType = 'GOLD';
        };

        $this->app->make(BroadcastBalance::class)->handle($unknown);
        $this->app->make(BroadcastSettlementStatus::class)->handle($unknown);
        $this->app->make(BroadcastMarketStatus::class)->handle($unknown);
        $this->app->make(BroadcastRfqActivity::class)->handle($unknown);
        $this->app->make(BroadcastNotification::class)->handle($unknown);

        Event::assertNothingDispatched();
    }

    /**
     * A trade whose instrument does not resolve to a code. Publishing on
     * `market.7` would name an internal key on a public channel and reach no
     * subscriber, so the public half is dropped — but the PRIVATE half, which
     * does not need a code, still goes out. Losing a trader's own fill because
     * a reference table lookup missed would be the wrong trade-off.
     */
    #[Test]
    public function an_unresolvable_instrument_drops_the_public_frame_and_keeps_the_private_one(): void
    {
        config()->set('goldb2b.broadcasting.instrument_symbols', []);

        Event::fake();

        $this->app->make(BroadcastTrade::class)->handle(new Doubles\TradeExecuted(
            tradeId: 1,
            tradeCode: 'TRD-00000001',
            instrumentId: 999,
            buyerOrganizationId: 11,
            sellerOrganizationId: 22,
            fineWeightMg: 100_000,
            pricePerGramRial: 78_480_000,
            grossAmountRial: 7_848_000_000,
            buyerFeeRial: 0,
            sellerFeeRial: 0,
            buyerNetRial: 7_848_000_000,
            sellerNetRial: 7_848_000_000,
        ));

        Event::assertNotDispatched(PublicTradeExecuted::class);
        Event::assertDispatchedTimes(PrivateTradeExecuted::class, 2);
    }

    // --- the reader itself -------------------------------------------------

    #[Test]
    public function event_shape_never_coerces(): void
    {
        $shape = EventShape::fromArray([
            'quantity' => '100000',
            'price' => 78_480_000,
            'side' => 'BUY',
            'flag' => 'yes',
            'levels' => 'not-an-array',
        ]);

        self::assertNull($shape->int('quantity'), 'a numeric string is a shape change, not an int');
        self::assertSame(78_480_000, $shape->int('price'));
        self::assertSame('BUY', $shape->string('side'));
        self::assertNull($shape->bool('flag'), '"yes" is not a bool');
        self::assertNull($shape->list('levels'));
        self::assertNull($shape->int('absent'));
        self::assertSame(7, $shape->intOr('absent', 7));
        self::assertFalse($shape->has('absent'));
    }

    #[Test]
    public function event_shape_accepts_either_spelling_of_a_renamed_field(): void
    {
        $shape = EventShape::fromArray(['grossAmount' => 7_848_000_000]);

        self::assertSame(
            7_848_000_000,
            $shape->firstInt(['grossAmountRial', 'grossAmount']),
            'the fallback spelling is what survives an upstream rename',
        );
    }
}
