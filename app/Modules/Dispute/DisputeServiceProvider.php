<?php

declare(strict_types=1);

namespace App\Modules\Dispute;

use App\Modules\Dispute\Console\ProcessDisputeDeadlinesCommand;
use App\Modules\Dispute\Contracts\DisputeHoldPort;
use App\Modules\Dispute\Contracts\TradePartiesProvider;
use App\Modules\Dispute\Infrastructure\EloquentTradePartiesProvider;
use App\Modules\Dispute\Infrastructure\Ledger\LedgerHoldAdapter;
use App\Modules\Dispute\Infrastructure\Ledger\NullHoldAdapter;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

/**
 * Wiring for the dispute module.
 *
 * DisputeHoldPort is bound through a closure rather than a class map because
 * the choice depends on what else is running: with a ledger implementation
 * present the real adapter locks funds for real, and without one the null
 * adapter lets a case still be filed while announcing loudly that nothing was
 * frozen. Deciding this at resolution time rather than at boot means the
 * binding is correct even when Ledger comes online after this provider.
 */
final class DisputeServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            // Reads Trading's tables to answer "was this member party to the
            // trade?". It guards on Schema::hasTable, so a deployment without
            // Trading degrades to the old Null behaviour — refusing every
            // trade-linked dispute — which is the safe direction: "I don't
            // know" must never be treated as "yes".
            TradePartiesProvider::class => EloquentTradePartiesProvider::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            ProcessDisputeDeadlinesCommand::class,
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/dispute.php', 'goldb2b.dispute');

        parent::register();

        $this->app->bind(DisputeHoldPort::class, static function ($app): DisputeHoldPort {
            if (! $app->bound(LedgerInterface::class)) {
                return new NullHoldAdapter;
            }

            return new LedgerHoldAdapter($app->make(LedgerInterface::class));
        });
    }
}
