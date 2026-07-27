<?php

declare(strict_types=1);

namespace App\Modules\Pricing;

use App\Modules\Pricing\Application\QuoteService;
use App\Modules\Pricing\Console\BuildOhlcCommand;
use App\Modules\Pricing\Console\FetchReferencePriceCommand;
use App\Modules\Pricing\Contracts\InstrumentDirectory;
use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Pricing\Contracts\QuoteWriterInterface;
use App\Modules\Pricing\Contracts\TradePrintSourceInterface;
use App\Modules\Pricing\Infrastructure\Drivers\ManualPriceDriver;
use App\Modules\Pricing\Infrastructure\Drivers\PriceDriverRegistry;
use App\Modules\Pricing\Infrastructure\Drivers\StubPriceDriver;
use App\Modules\Pricing\Infrastructure\EloquentPriceReader;
use App\Modules\Pricing\Infrastructure\NullInstrumentDirectory;
use App\Modules\Pricing\Infrastructure\NullTradePrintSource;
use App\Modules\Pricing\Infrastructure\QuoteReferencePriceOracle;
use App\Modules\Shared\Concerns\ModuleServiceProvider;
use App\Modules\Shared\Contracts\ReferencePriceOracle;

final class PricingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bindings(): array
    {
        return [
            PriceReaderInterface::class => EloquentPriceReader::class,
            QuoteWriterInterface::class => QuoteService::class,
            // Replaced by the Trading module once it owns a trades table.
            TradePrintSourceInterface::class => NullTradePrintSource::class,
            // Ditto for the instruments table, which Trading owns.
            InstrumentDirectory::class => NullInstrumentDirectory::class,

            // Shared declared this port for GET /balances, which lives in
            // Ledger — a module that may not depend on Pricing.
            ReferencePriceOracle::class => QuoteReferencePriceOracle::class,
        ];
    }

    protected function consoleCommands(): array
    {
        return [
            FetchReferencePriceCommand::class,
            BuildOhlcCommand::class,
        ];
    }

    public function register(): void
    {
        parent::register();

        // Module-local defaults; config/goldb2b.php always wins where both set a key.
        $this->mergeConfigFrom(__DIR__.'/config.php', 'goldb2b.pricing');

        $this->app->singleton(ManualPriceDriver::class);
        $this->app->singleton(StubPriceDriver::class);

        $this->app->singleton(PriceDriverRegistry::class, function ($app): PriceDriverRegistry {
            return new PriceDriverRegistry([
                $app->make(ManualPriceDriver::class),
                $app->make(StubPriceDriver::class),
            ]);
        });

        // QuoteService owns market_quotes; a single instance keeps the alert
        // throttle cache warm within a request.
        $this->app->singleton(QuoteService::class);
    }
}
