<?php

declare(strict_types=1);

namespace App\Modules\Accounting;

use App\Modules\Accounting\Console\CloseAccountingPeriodCommand;
use App\Modules\Accounting\Contracts\JournalPosterInterface;
use App\Modules\Accounting\Contracts\MarketPriceProvider;
use App\Modules\Accounting\Infrastructure\NullMarketPriceProvider;
use App\Modules\Accounting\Listeners\PostPenaltyVoucher;
use App\Modules\Accounting\Listeners\PostSettlementVoucher;
use App\Modules\Accounting\Listeners\PostTradeVoucher;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

/**
 * Wiring for the member accounting module.
 *
 * The listener map keys are event class names given as STRINGS on purpose.
 * Trading and Settlement are being built in parallel and Accounting is
 * forbidden from importing their classes, so binding by name lets the two sides
 * meet at the event bus: if the event class never appears, nothing fires; if it
 * appears with a different shape, the listener reads what it can and no-ops.
 */
final class AccountingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            JournalPosterInterface::class => Application\JournalPoster::class,

            // No price feed by default: F17 reports "unknown" rather than zero.
            MarketPriceProvider::class => NullMarketPriceProvider::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            CloseAccountingPeriodCommand::class,
        ];
    }

    /** @return array<string, array<class-string>> */
    protected function listeners(): array
    {
        return [
            'App\Modules\Trading\Events\TradeExecuted' => [PostTradeVoucher::class],
            'App\Modules\Trading\Events\TradeSettled' => [PostTradeVoucher::class],
            'App\Modules\Settlement\Events\SettlementCompleted' => [PostSettlementVoucher::class],
            'App\Modules\Settlement\Events\PenaltyCharged' => [PostPenaltyVoucher::class],
            'App\Modules\Settlement\Events\SettlementDefaulted' => [PostPenaltyVoucher::class],
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/accounting.php', 'goldb2b.accounting');

        parent::register();
    }
}
