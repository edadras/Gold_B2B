<?php

declare(strict_types=1);

namespace App\Modules\Reputation;

use App\Modules\Reputation\Application\PublicProfileService;
use App\Modules\Reputation\Application\RecomputeService;
use App\Modules\Reputation\Application\StatsUpdater;
use App\Modules\Reputation\Application\TierEvaluator;
use App\Modules\Reputation\Console\RecomputeReputationCommand;
use App\Modules\Reputation\Contracts\CounterpartyCounter;
use App\Modules\Reputation\Contracts\ReputationDirectory;
use App\Modules\Reputation\Infrastructure\RelationTableCounterpartyCounter;
use App\Modules\Reputation\Infrastructure\ReputationVerificationTierDirectory;
use App\Modules\Reputation\Listeners\UpdateStatsFromDispute;
use App\Modules\Reputation\Listeners\UpdateStatsFromSettlement;
use App\Modules\Reputation\Listeners\UpdateVerificationFlags;
use App\Modules\Shared\Concerns\ModuleServiceProvider;
use App\Modules\Shared\Contracts\VerificationTierDirectory;

final class ReputationServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/reputation.php', 'goldb2b.reputation');

        parent::register();

        $this->app->singleton(StatsUpdater::class);
        $this->app->singleton(TierEvaluator::class);
        $this->app->singleton(PublicProfileService::class);
        $this->app->singleton(RecomputeService::class);
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            ReputationDirectory::class => PublicProfileService::class,
            // Shared's port; this is what makes `verification_tier` appear in
            // the login response and in /auth/me without Identity importing
            // Reputation.
            VerificationTierDirectory::class => ReputationVerificationTierDirectory::class,
            // Swap this binding to move `distinct_counterparties` onto a
            // Counterparty API call without touching the recompute job.
            CounterpartyCounter::class => RelationTableCounterpartyCounter::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            RecomputeReputationCommand::class,
        ];
    }

    /**
     * Statistics arrive from modules this one may not depend on, so the events
     * are named as strings and each listener validates the payload's shape
     * before touching a counter.
     *
     * @return array<class-string|string, array<class-string>>
     */
    protected function listeners(): array
    {
        return [
            'App\Modules\Settlement\Events\SettlementCompleted' => [UpdateStatsFromSettlement::class],
            'App\Modules\Settlement\Events\SettlementDefaulted' => [UpdateStatsFromSettlement::class],
            'App\Modules\Dispute\Events\DisputeResolved' => [UpdateStatsFromDispute::class],
            'App\Modules\Kyc\Events\KycApproved' => [UpdateVerificationFlags::class],
            // NOT registered: 'App\Modules\Kyc\Events\BankAccountVerified'.
            // `kyc.bank_accounts.verified_at` exists and the API exposes it,
            // but nothing in the platform ever writes it — bank-account
            // verification is not implemented. A listener waiting on an event
            // no module emits looks like working wiring and is not, so the
            // registration is left out and the gap stated instead.
            // UpdateVerificationFlags already handles the event by name and
            // needs no change on the day Kyc grows the flow.
        ];
    }
}
