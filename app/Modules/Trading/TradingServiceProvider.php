<?php

declare(strict_types=1);

namespace App\Modules\Trading;

use App\Modules\Pricing\Contracts\InstrumentDirectory;
use App\Modules\Pricing\Contracts\TradePrintSourceInterface;
use App\Modules\Pricing\Events\CircuitBreakerTriggered;
use App\Modules\Pricing\Events\NoPriceAvailable;
use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Contracts\TradingExposureReaderInterface;
use App\Modules\Shared\Concerns\ModuleServiceProvider;
use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Console\CloseMarketCommand;
use App\Modules\Trading\Console\ExpireOrdersCommand;
use App\Modules\Trading\Console\ExpireStaleRfqsCommand;
use App\Modules\Trading\Console\HaltMarketCommand;
use App\Modules\Trading\Console\OpenMarketCommand;
use App\Modules\Trading\Infrastructure\Readers\EloquentInstrumentDirectory;
use App\Modules\Trading\Infrastructure\Readers\EloquentTradeHistoryReader;
use App\Modules\Trading\Infrastructure\Readers\EloquentTradePrintSource;
use App\Modules\Trading\Infrastructure\Readers\EloquentTradingExposureReader;
use App\Modules\Trading\Listeners\CancelOrdersOnOrganizationRestriction;
use App\Modules\Trading\Listeners\PauseMarketOnCircuitBreaker;
use App\Modules\Trading\Listeners\PauseMarketOnMissingPrice;
use App\Modules\Trading\Listeners\PublishOrderBookChange;

/**
 * Wires the Trading module.
 *
 * Three of the bindings are the interesting ones: Risk and Pricing each
 * declared a reader interface they needed and bound a null implementation to
 * keep themselves runnable while Trading did not exist. Registering the real
 * ones here is what switches those modules on — the AML history rules, the
 * exposure limits and the candle builder all start returning real data the
 * moment this provider loads, with no change to their own code.
 */
final class TradingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            // Interfaces other modules declared and left stubbed.
            TradeHistoryReaderInterface::class => EloquentTradeHistoryReader::class,
            TradingExposureReaderInterface::class => EloquentTradingExposureReader::class,
            TradePrintSourceInterface::class => EloquentTradePrintSource::class,
            // Pricing keys its rows on instrument_id but the API speaks codes,
            // and the instruments table is ours.
            InstrumentDirectory::class => EloquentInstrumentDirectory::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            OpenMarketCommand::class,
            CloseMarketCommand::class,
            HaltMarketCommand::class,
            ExpireOrdersCommand::class,
            ExpireStaleRfqsCommand::class,
        ];
    }

    /**
     * @return array<string, array<class-string>>
     */
    protected function listeners(): array
    {
        $listeners = [
            CircuitBreakerTriggered::class => [PauseMarketOnCircuitBreaker::class],
            NoPriceAvailable::class => [PauseMarketOnMissingPrice::class],
        ];

        // Every event that can change the visible ladder is folded into one
        // OrderBookChanged. OrderBookChanged itself is not in this list, so
        // there is no cycle.
        foreach ([
            Events\OrderPlaced::class,
            Events\OrderCancelled::class,
            Events\OrderExpired::class,
            Events\OrderFilled::class,
            Events\OrderPartiallyFilled::class,
            Events\OpeningAuctionCompleted::class,
        ] as $event) {
            $listeners[$event] = [PublishOrderBookChange::class];
        }

        // Identity's events are referenced by string and guarded, because
        // Identity is a separate module that a deployment slice may not
        // include. Listening for a class that does not exist is harmless in
        // Laravel, but the guard makes the intent explicit and keeps a typo
        // from silently registering a listener that can never fire.
        foreach ([
            'App\Modules\Identity\Events\OrganizationSuspended',
            'App\Modules\Identity\Events\OrganizationRestricted',
        ] as $event) {
            if (class_exists($event)) {
                $listeners[$event] = [CancelOrdersOnOrganizationRestriction::class];
            }
        }

        return $listeners;
    }

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/config.php', 'goldb2b.trading');

        // Instruments barely change and every order placement reads one, so the
        // repository memoises them for the life of the request.
        $this->app->singleton(InstrumentRepository::class);

        // Singleton so its "last published ladder" memory survives the several
        // order events a single crossing placement fires.
        $this->app->singleton(PublishOrderBookChange::class);
    }
}
