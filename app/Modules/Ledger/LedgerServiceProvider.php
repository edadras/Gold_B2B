<?php

declare(strict_types=1);

namespace App\Modules\Ledger;

use App\Modules\Ledger\Application\GoldLedgerService;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Application\RialLedgerService;
use App\Modules\Ledger\Console\ReconcileLedgerCommand;
use App\Modules\Ledger\Console\SnapshotLedgerCommand;
use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Listeners\CreateLedgerAccountsForOrganization;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

final class LedgerServiceProvider extends ModuleServiceProvider
{
    /**
     * Identity does not exist yet. The listener is registered against the event
     * class *name*, so nothing is autoloaded and nothing fatals; class_exists()
     * keeps the map empty until Identity ships its event.
     */
    private const ORGANIZATION_ACTIVATED = 'App\Modules\Identity\Events\OrganizationActivated';

    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bindings(): array
    {
        return [
            LedgerInterface::class => LedgerService::class,
            GoldLedgerInterface::class => GoldLedgerService::class,
            RialLedgerInterface::class => RialLedgerService::class,
        ];
    }

    protected function consoleCommands(): array
    {
        return [
            ReconcileLedgerCommand::class,
            SnapshotLedgerCommand::class,
        ];
    }

    protected function listeners(): array
    {
        if (! class_exists(self::ORGANIZATION_ACTIVATED)) {
            return [];
        }

        return [
            self::ORGANIZATION_ACTIVATED => [CreateLedgerAccountsForOrganization::class],
        ];
    }
}
