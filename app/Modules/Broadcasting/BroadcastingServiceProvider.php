<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting;

use App\Modules\Broadcasting\Application\BroadcastGateway;
use App\Modules\Broadcasting\Application\ChannelAuthorizer;
use App\Modules\Broadcasting\Application\MarketBroadcaster;
use App\Modules\Broadcasting\Application\MemberBroadcaster;
use App\Modules\Broadcasting\Contracts\BroadcastThrottle;
use App\Modules\Broadcasting\Contracts\InstrumentSymbols;
use App\Modules\Broadcasting\Infrastructure\BroadcastSigner;
use App\Modules\Broadcasting\Infrastructure\DatabaseInstrumentSymbols;
use App\Modules\Broadcasting\Infrastructure\RedisBroadcastThrottle;
use App\Modules\Broadcasting\Listeners\BroadcastBalance;
use App\Modules\Broadcasting\Listeners\BroadcastMarketStatus;
use App\Modules\Broadcasting\Listeners\BroadcastNotification;
use App\Modules\Broadcasting\Listeners\BroadcastOrderBookDepth;
use App\Modules\Broadcasting\Listeners\BroadcastOrderLifecycle;
use App\Modules\Broadcasting\Listeners\BroadcastReferencePrice;
use App\Modules\Broadcasting\Listeners\BroadcastRfqActivity;
use App\Modules\Broadcasting\Listeners\BroadcastSettlementStatus;
use App\Modules\Broadcasting\Listeners\BroadcastTrade;
use App\Modules\Shared\Concerns\ModuleServiceProvider;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The realtime layer (docs/05-api/03-realtime-webhooks.md part A).
 *
 * EVERY LISTENER IS REGISTERED AGAINST A STRING. Broadcasting may depend only
 * on Shared and Identity (tests/Architecture/ArchitectureTest.php), so it
 * cannot import Trading's, Ledger's, Settlement's or Pricing's event classes —
 * and it does not need to. Laravel's dispatcher matches on the class name, and
 * the listeners read the payload through Broadcasting\Domain\EventShape, which
 * tolerates a field that moved, changed type or vanished.
 *
 * That is the trade this module makes: no compile-time knowledge of the events
 * it consumes, in exchange for being addable and removable without touching a
 * single line in any other module. The domain events themselves stay exactly
 * as their owners wrote them — readonly scalar records, no ShouldBroadcast, no
 * new interface — because six other listeners already depend on their shape.
 *
 * Channels are registered from routes/channels.php here rather than through
 * bootstrap/app.php's `withRouting(channels: ...)`. That helper also calls
 * Broadcast::routes(), which would mount the framework's session-authenticated
 * `/broadcasting/auth` alongside the Sanctum-authenticated one this module
 * owns — a second door onto the same private channels, on a different guard.
 */
final class BroadcastingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/broadcasting.php', 'goldb2b.broadcasting');

        parent::register();

        $this->app->singleton(BroadcastThrottle::class, static function ($app): BroadcastThrottle {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('goldb2b.broadcasting', []);

            return new RedisBroadcastThrottle(
                redis: $app->make(RedisFactory::class),
                connection: (string) ($config['throttle_connection'] ?? 'default'),
                prefix: (string) ($config['throttle_prefix'] ?? 'goldb2b:bcast:throttle'),
                windowMs: (int) ($config['aggregation_window_ms'] ?? 100),
            );
        });

        $this->app->singleton(InstrumentSymbols::class, static function ($app): InstrumentSymbols {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('goldb2b.broadcasting', []);

            /** @var array<int, string> $static */
            $static = is_array($config['instrument_symbols'] ?? null) ? $config['instrument_symbols'] : [];

            return new DatabaseInstrumentSymbols(
                connection: $app->make(ConnectionResolverInterface::class)->connection(),
                cache: $app->make(CacheFactory::class)->store(),
                static: $static,
                ttlSeconds: (int) ($config['instrument_cache_ttl'] ?? 300),
            );
        });

        $this->app->singleton(BroadcastSigner::class, static function ($app): BroadcastSigner {
            $connection = (string) $app['config']->get('goldb2b.broadcasting.auth_connection', 'reverb');

            return new BroadcastSigner(
                appKey: $app['config']->get("broadcasting.connections.{$connection}.key"),
                appSecret: $app['config']->get("broadcasting.connections.{$connection}.secret"),
            );
        });

        $this->app->singleton(BroadcastGateway::class);
        $this->app->singleton(MarketBroadcaster::class);
        $this->app->singleton(MemberBroadcaster::class);
        $this->app->singleton(ChannelAuthorizer::class);
        // Singleton so its "does Notification handle this?" answers are worked
        // out once rather than on every dispatched event.
        $this->app->singleton(BroadcastNotification::class);
    }

    public function boot(): void
    {
        parent::boot();

        $channels = dirname(__DIR__, 3).'/routes/channels.php';

        if (is_file($channels)) {
            require $channels;
        }
    }

    /**
     * Producing modules named as strings — see the class docblock.
     *
     * @return array<class-string|string, array<class-string>>
     */
    protected function listeners(): array
    {
        return [
            // --- Trading: the book and the tape ---------------------------
            'App\Modules\Trading\Events\TradeExecuted' => [BroadcastTrade::class],
            'App\Modules\Trading\Events\OrderPlaced' => [BroadcastOrderLifecycle::class],
            'App\Modules\Trading\Events\OrderPartiallyFilled' => [BroadcastOrderLifecycle::class],
            'App\Modules\Trading\Events\OrderFilled' => [BroadcastOrderLifecycle::class],
            'App\Modules\Trading\Events\OrderCancelled' => [BroadcastOrderLifecycle::class],
            'App\Modules\Trading\Events\OrderExpired' => [BroadcastOrderLifecycle::class],
            'App\Modules\Trading\Events\OrderRejected' => [
                BroadcastOrderLifecycle::class,
                BroadcastNotification::class,
            ],

            // Does not exist yet — the depth seam. See BroadcastOrderBookDepth.
            'App\Modules\Trading\Events\OrderBookChanged' => [BroadcastOrderBookDepth::class],

            // --- Trading: session state -----------------------------------
            'App\Modules\Trading\Events\MarketSessionOpened' => [BroadcastMarketStatus::class],
            'App\Modules\Trading\Events\MarketSessionClosed' => [BroadcastMarketStatus::class],
            'App\Modules\Trading\Events\MarketPaused' => [BroadcastMarketStatus::class],

            // --- Trading: RFQ and OTC -------------------------------------
            'App\Modules\Trading\Events\RfqCreated' => [BroadcastRfqActivity::class],
            'App\Modules\Trading\Events\RfqQuoted' => [BroadcastRfqActivity::class],
            'App\Modules\Trading\Events\RfqAccepted' => [BroadcastRfqActivity::class],
            'App\Modules\Trading\Events\RfqExpired' => [BroadcastRfqActivity::class],
            'App\Modules\Trading\Events\OtcOfferCreated' => [BroadcastRfqActivity::class],
            'App\Modules\Trading\Events\OtcOfferCountered' => [BroadcastRfqActivity::class],
            'App\Modules\Trading\Events\OtcOfferAccepted' => [BroadcastRfqActivity::class],
            'App\Modules\Trading\Events\OtcOfferRejected' => [BroadcastRfqActivity::class],

            // --- Pricing --------------------------------------------------
            'App\Modules\Pricing\Events\PriceTickAccepted' => [BroadcastReferencePrice::class],
            'App\Modules\Pricing\Events\CircuitBreakerTriggered' => [BroadcastMarketStatus::class],
            'App\Modules\Pricing\Events\NoPriceAvailable' => [BroadcastMarketStatus::class],
            'App\Modules\Pricing\Events\PriceAlertTriggered' => [BroadcastNotification::class],

            // --- Ledger: balances, never throttled ------------------------
            'App\Modules\Ledger\Events\BalanceReserved' => [BroadcastBalance::class],
            'App\Modules\Ledger\Events\ReservationReleased' => [BroadcastBalance::class],
            'App\Modules\Ledger\Events\TransferCompleted' => [BroadcastBalance::class],
            'App\Modules\Ledger\Events\BalanceDiscrepancyDetected' => [BroadcastNotification::class],

            // --- Settlement -----------------------------------------------
            'App\Modules\Settlement\Events\SettlementOpened' => [BroadcastSettlementStatus::class],
            'App\Modules\Settlement\Events\PaymentDeclared' => [BroadcastSettlementStatus::class],
            'App\Modules\Settlement\Events\PaymentConfirmed' => [BroadcastSettlementStatus::class],
            'App\Modules\Settlement\Events\GoldTransferred' => [BroadcastSettlementStatus::class],
            'App\Modules\Settlement\Events\SettlementCompleted' => [BroadcastSettlementStatus::class],
            'App\Modules\Settlement\Events\SettlementCancelled' => [BroadcastSettlementStatus::class],
            'App\Modules\Settlement\Events\SettlementOverdue' => [
                BroadcastSettlementStatus::class,
                BroadcastNotification::class,
            ],
            'App\Modules\Settlement\Events\SettlementDefaulted' => [
                BroadcastSettlementStatus::class,
                BroadcastNotification::class,
            ],
            'App\Modules\Settlement\Events\SettlementReversed' => [
                BroadcastSettlementStatus::class,
                BroadcastNotification::class,
            ],

            // --- Everything else that lights the bell ---------------------
            'App\Modules\Custody\Events\AssayVarianceDetected' => [BroadcastNotification::class],
            'App\Modules\Dispute\Events\DisputeOpened' => [BroadcastNotification::class],
            'App\Modules\Dispute\Events\DisputeResolved' => [BroadcastNotification::class],
            'App\Modules\Reputation\Events\TierPromoted' => [BroadcastNotification::class],

            // The good path: an already-persisted, already-deduplicated row.
            'App\Modules\Notification\Events\NotificationDelivered' => [BroadcastNotification::class],
        ];
    }
}
