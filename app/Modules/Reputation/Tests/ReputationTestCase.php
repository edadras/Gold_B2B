<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Tests;

use App\Modules\Reputation\Application\StatsUpdater;
use App\Modules\Reputation\Domain\VerificationTier;
use App\Modules\Reputation\ReputationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class ReputationTestCase extends TestCase
{
    use RefreshDatabase;

    protected StatsUpdater $stats;

    /**
     * Registered here rather than in setUp(): RefreshDatabase migrates from
     * setUpTraits(), which runs before any afterApplicationCreated callback, so
     * a provider registered later would contribute no migrations.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->app->register(ReputationServiceProvider::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stats = $this->app->make(StatsUpdater::class);
    }

    /**
     * A member with a clean record, positioned just below a given tier's
     * non-trading criteria so a test only has to move the metric it is about.
     */
    protected function seedMember(
        int $organizationId,
        int $memberSinceDays = 400,
        bool $kycBasic = true,
        bool $kycFull = true,
        bool $bank = true,
        int $distinctCounterparties = 0,
    ): void {
        $this->stats->ensure($organizationId, Carbon::now()->subDays($memberSinceDays));
        $this->stats->setVerification($organizationId, $kycBasic, $kycFull, $bank);

        DB::table('reputation_stats')
            ->where('organization_id', $organizationId)
            ->update(['distinct_counterparties' => $distinctCounterparties]);
    }

    protected function tierOf(int $organizationId): VerificationTier
    {
        $value = DB::table('reputation_stats')
            ->where('organization_id', $organizationId)
            ->value('verification_tier');

        return VerificationTier::from((string) $value);
    }

    /** @param  array<string, int|string>  $columns */
    protected function forceStats(int $organizationId, array $columns): void
    {
        DB::table('reputation_stats')
            ->where('organization_id', $organizationId)
            ->update($columns);
    }
}
