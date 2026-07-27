<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests;

use App\Modules\Broadcasting\Events\DepthUpdated;
use App\Modules\Broadcasting\Events\MarketStatusChanged;
use App\Modules\Broadcasting\Events\PublicTradeExecuted;
use App\Modules\Broadcasting\Events\QuoteUpdated;
use App\Modules\Broadcasting\Events\ReferencePriceUpdated;
use App\Modules\Broadcasting\Listeners\BroadcastTrade;
use App\Modules\Broadcasting\Tests\Doubles\TradeExecuted as FakeTradeExecuted;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * §3.3: «کانال عمومی **هرگز** organization_id، نام عضو یا هر شناسه‌ای که بتوان
 * از آن هویت را استنتاج کرد منتشر نمی‌کند.»
 *
 * Asserted on the SERIALISED array, not on the class's constructor signature.
 * A payload builder that pulls a field in from somewhere unexpected, a
 * `$data` map that grows a key, an id that leaks through a nested array — none
 * of those show up in a type signature, and all of them show up here.
 *
 * The organisation ids used are deliberately implausible as prices or
 * quantities (987654321 / 876543219) so that finding one in the payload cannot
 * be a coincidence.
 */
final class PublicPayloadPrivacyTest extends BroadcastingTestCase
{
    private const BUYER_ORG_ID = 987654321;

    private const SELLER_ORG_ID = 876543219;

    private const BUYER_USER_ID = 765432198;

    /** Field names that must never appear as a key in a public payload. */
    private const FORBIDDEN_KEYS = [
        'organization_id', 'organizationid', 'org_id', 'orgid',
        'buyer_organization_id', 'seller_organization_id',
        'counterparty', 'counterparty_id', 'counterparty_organization_id',
        'member', 'member_id', 'member_name', 'display_name',
        'user_id', 'userid', 'trader', 'owner', 'account_id',
        'buyer', 'seller', 'buyer_id', 'seller_id',
        'order_id', 'order_code', 'trade_code', 'settlement_code',
    ];

    #[Test]
    public function the_public_trade_print_carries_no_identity(): void
    {
        $event = new PublicTradeExecuted(
            instrumentCode: 'GOLD-995-T0',
            priceRial: 78_480_000,
            quantityMg: 100_000,
            takerSide: 'BUY',
            executedAt: '2026-07-27T09:15:33.480Z',
        );

        $payload = $event->broadcastWith();

        self::assertSame(
            ['instrument', 'price_rial', 'quantity_mg', 'taker_side', 'executed_at'],
            array_keys($payload),
            'the public tape is an allow-list; a new key here is a leak until proven otherwise',
        );

        $this->assertPayloadIsAnonymous($payload);
    }

    #[Test]
    public function the_public_quote_carries_no_identity(): void
    {
        $event = new QuoteUpdated(
            instrumentCode: 'GOLD-995-T0',
            bestBidRial: 78_420_000,
            bestBidQtyMg: 300_000,
            bestAskRial: 78_480_000,
            bestAskQtyMg: 350_000,
            lastPriceRial: 78_450_000,
            dayChangeBps: 42,
            timestamp: '2026-07-27T09:15:33.412Z',
        );

        $payload = $event->broadcastWith();

        self::assertSame(60_000, $payload['spread'], 'spread is derived, not carried');
        $this->assertPayloadIsAnonymous($payload);
    }

    #[Test]
    public function the_depth_ladder_carries_counts_not_identities(): void
    {
        $event = new DepthUpdated(
            instrumentCode: 'GOLD-995-T0',
            bids: [[78_420_000, 300_000, 2], [78_400_000, 250_000, 1]],
            asks: [[78_480_000, 350_000, 2]],
            timestamp: '2026-07-27T09:15:33.412Z',
        );

        $payload = $event->broadcastWith();

        self::assertSame([[78_420_000, 300_000, 2], [78_400_000, 250_000, 1]], $payload['bids']);
        $this->assertPayloadIsAnonymous($payload);
    }

    /**
     * A producer that attaches an owning organisation to a depth level must
     * not get it published. The narrowing in DepthUpdated::levels() is the
     * last gate before a public socket, so it is tested with hostile input.
     */
    #[Test]
    public function a_depth_level_carrying_an_extra_identifier_is_narrowed_away(): void
    {
        $event = new DepthUpdated(
            instrumentCode: 'GOLD-995-T0',
            // @phpstan-ignore-next-line — deliberately the wrong shape
            bids: [[78_420_000, 300_000, 2, self::BUYER_ORG_ID]],
            asks: [],
        );

        $payload = $event->broadcastWith();

        self::assertSame([[78_420_000, 300_000, 2]], $payload['bids']);
        $this->assertPayloadIsAnonymous($payload);
    }

    #[Test]
    public function market_status_and_reference_price_carry_no_identity(): void
    {
        $this->assertPayloadIsAnonymous(
            (new MarketStatusChanged(
                instrumentCode: 'GOLD-995-T0',
                status: MarketStatusChanged::STATUS_PAUSED,
                reasonCode: 'CIRCUIT_BREAKER',
                reason: 'نوسان بیش از حد',
            ))->broadcastWith(),
        );

        $this->assertPayloadIsAnonymous(
            (new ReferencePriceUpdated(
                priceType: 'GOLD_18K_GRAM',
                valueRial: 78_450_000,
                effectiveValueRial: 78_440_000,
                observedAt: '2026-07-27T09:15:33.412Z',
            ))->broadcastWith(),
        );
    }

    /**
     * The end-to-end version: run a real trade through the listener and check
     * everything that landed on a PUBLIC channel. This is the assertion that
     * would catch a future change routing a private event onto `market.*`.
     */
    #[Test]
    public function nothing_a_real_trade_puts_on_a_public_channel_names_a_party(): void
    {
        config()->set('goldb2b.broadcasting.instrument_symbols', [7 => 'GOLD-995-T0']);

        Event::fake();

        $this->app->make(BroadcastTrade::class)->handle(new FakeTradeExecuted(
            tradeId: 88231,
            tradeCode: 'TRD-00088231',
            instrumentId: 7,
            buyerOrganizationId: self::BUYER_ORG_ID,
            sellerOrganizationId: self::SELLER_ORG_ID,
            fineWeightMg: 100_000,
            pricePerGramRial: 78_480_000,
            grossAmountRial: 7_848_000_000,
            buyerFeeRial: 11_772_000,
            sellerFeeRial: 11_772_000,
            buyerNetRial: 7_859_772_000,
            sellerNetRial: 7_836_228_000,
            makerSide: 'SELL',
            settlementCode: 'STL-00088231',
            settlementDeadline: '2026-07-27T17:00:00Z',
            executedAt: '2026-07-27T09:15:33.480Z',
        ));

        $publicPayloads = 0;

        foreach ($this->dispatchedBroadcasts() as $event) {
            foreach ($event->broadcastOn() as $channel) {
                if ($channel instanceof PrivateChannel || ! $channel instanceof Channel) {
                    continue;
                }

                $publicPayloads++;
                $this->assertPayloadIsAnonymous($this->payloadOf($event));
            }
        }

        self::assertGreaterThan(0, $publicPayloads, 'the trade should have produced public frames');
    }

    /**
     * The counterpart: the PRIVATE frame is allowed — required, even — to name
     * the counterparty. A test that only proved the public payload is clean
     * would also pass if the counterparty had been dropped everywhere.
     */
    #[Test]
    public function the_private_frame_does_name_the_counterparty(): void
    {
        $buyer = $this->organization();
        $seller = $this->organization();

        config()->set('goldb2b.broadcasting.instrument_symbols', [7 => 'GOLD-995-T0']);

        Event::fake();

        $this->app->make(BroadcastTrade::class)->handle(new FakeTradeExecuted(
            tradeId: 88231,
            tradeCode: 'TRD-00088231',
            instrumentId: 7,
            buyerOrganizationId: $buyer->id,
            sellerOrganizationId: $seller->id,
            fineWeightMg: 100_000,
            pricePerGramRial: 78_480_000,
            grossAmountRial: 7_848_000_000,
            buyerFeeRial: 11_772_000,
            sellerFeeRial: 11_772_000,
            buyerNetRial: 7_859_772_000,
            sellerNetRial: 7_836_228_000,
            makerSide: 'SELL',
            settlementCode: 'STL-00088231',
            settlementDeadline: '2026-07-27T17:00:00Z',
            executedAt: '2026-07-27T09:15:33.480Z',
        ));

        $seenByBuyer = null;

        foreach ($this->dispatchedBroadcasts() as $event) {
            foreach ($event->broadcastOn() as $channel) {
                if ($channel instanceof PrivateChannel && $channel->name === 'private-org.'.$buyer->id) {
                    $payload = $this->payloadOf($event);

                    if (($payload['side'] ?? null) === 'BUY') {
                        $seenByBuyer = $payload;
                    }
                }
            }
        }

        self::assertNotNull($seenByBuyer, 'the buyer must receive their own trade');
        self::assertSame($seller->id, $seenByBuyer['counterparty']['id']);
        self::assertSame($seller->display_name, $seenByBuyer['counterparty']['display_name']);
        self::assertSame(11_772_000, $seenByBuyer['fee_rial'], 'each side sees its own fee');
    }

    // --- helpers -----------------------------------------------------------

    /** @return list<ShouldBroadcast> */
    private function dispatchedBroadcasts(): array
    {
        $events = [];

        foreach (Event::dispatchedEvents() as $class => $occurrences) {
            if (! is_string($class) || ! is_subclass_of($class, ShouldBroadcast::class)) {
                continue;
            }

            foreach ($occurrences as $occurrence) {
                $events[] = $occurrence[0];
            }
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private function payloadOf(ShouldBroadcast $event): array
    {
        return method_exists($event, 'broadcastWith') ? $event->broadcastWith() : [];
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadIsAnonymous(array $payload): void
    {
        $flat = $this->flatten($payload);

        foreach ($flat as $key => $value) {
            // Every segment of the path, so `counterparty.id` is caught by the
            // `counterparty` entry and not only by an exact full-path match.
            foreach (explode('.', (string) $key) as $segment) {
                self::assertNotContains(
                    strtolower($segment),
                    self::FORBIDDEN_KEYS,
                    sprintf('public payload carries the identifying key [%s]', $key),
                );
            }

            foreach ([self::BUYER_ORG_ID, self::SELLER_ORG_ID, self::BUYER_USER_ID] as $identifier) {
                self::assertNotSame(
                    $identifier,
                    is_numeric($value) ? (int) $value : null,
                    sprintf('public payload carries identifier %d under [%s]', $identifier, $key),
                );
            }
        }

        // Belt and braces: the JSON a client actually receives.
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        self::assertIsString($json);

        foreach ([self::BUYER_ORG_ID, self::SELLER_ORG_ID, self::BUYER_USER_ID] as $identifier) {
            self::assertStringNotContainsString((string) $identifier, $json);
        }

        self::assertStringNotContainsString('organization', strtolower($json));
        self::assertStringNotContainsString('counterparty', strtolower($json));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
