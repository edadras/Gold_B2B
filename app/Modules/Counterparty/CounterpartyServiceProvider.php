<?php

declare(strict_types=1);

namespace App\Modules\Counterparty;

use App\Modules\Counterparty\Application\BalanceConfirmationService;
use App\Modules\Counterparty\Application\ConcentrationAnalyser;
use App\Modules\Counterparty\Application\CreditLimitService;
use App\Modules\Counterparty\Application\MemberDirectoryService;
use App\Modules\Counterparty\Application\ReconciliationService;
use App\Modules\Counterparty\Application\RelationService;
use App\Modules\Counterparty\Application\StatementService;
use App\Modules\Counterparty\Console\ReconcileRelationsCommand;
use App\Modules\Counterparty\Contracts\CounterpartyRelations;
use App\Modules\Counterparty\Listeners\ApplySettlementToRelation;
use App\Modules\Counterparty\Listeners\RecordRelationshipIncident;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

final class CounterpartyServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        // Module-owned defaults are merged under `goldb2b.counterparty` so the
        // module can gain a knob without editing the shared config file.
        $this->mergeConfigFrom(__DIR__.'/Config/counterparty.php', 'goldb2b.counterparty');

        parent::register();

        $this->app->singleton(RelationService::class);
        $this->app->singleton(StatementService::class);
        $this->app->singleton(CreditLimitService::class);
        $this->app->singleton(ConcentrationAnalyser::class);
        $this->app->singleton(BalanceConfirmationService::class);
        $this->app->singleton(ReconciliationService::class);
        // Backs GET /members/search; resolves Identity's directory contract.
        $this->app->singleton(MemberDirectoryService::class);
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            CounterpartyRelations::class => RelationService::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            ReconcileRelationsCommand::class,
        ];
    }

    /**
     * Cross-module reactions, keyed by event class *name*.
     *
     * Counterparty may depend only on Shared and Identity, so it cannot import
     * these classes; Laravel's event dispatcher matches on the string, and the
     * listeners validate the payload shape before touching a balance. A module
     * that never ships simply means a listener that never fires.
     *
     * @return array<class-string|string, array<class-string>>
     */
    protected function listeners(): array
    {
        return [
            'App\Modules\Settlement\Events\SettlementCompleted' => [ApplySettlementToRelation::class],
            'App\Modules\Settlement\Events\SettlementOverdue' => [RecordRelationshipIncident::class],
            'App\Modules\Settlement\Events\SettlementDefaulted' => [RecordRelationshipIncident::class],
            'App\Modules\Dispute\Events\DisputeOpened' => [RecordRelationshipIncident::class],
        ];
    }
}
