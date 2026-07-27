<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Console;

use App\Modules\Reputation\Application\RecomputeService;
use Illuminate\Console\Command;

/**
 * `php artisan reputation:recompute` — the daily job of §14.4 and §14.6.
 *
 * It promotes and never demotes: there is no flag on this command that can
 * lower a tier, because §14.4 puts that decision in a compliance officer's
 * hands. See TierEvaluator::demote().
 */
final class RecomputeReputationCommand extends Command
{
    protected $signature = 'reputation:recompute
                            {--skip-tiers : Recompute statistics only, without running the promotion pass}';

    protected $description = 'Recompute distinct counterparties, roll up period statistics and promote qualifying members';

    public function handle(RecomputeService $recompute): int
    {
        $changed = $recompute->recomputeDistinctCounterparties();
        $this->info(sprintf('distinct_counterparties updated for %d member(s).', $changed));

        $periods = $recompute->rollUpPeriods();
        $this->info(sprintf('%d period roll-up row(s) written.', $periods));

        if ($this->option('skip-tiers')) {
            return self::SUCCESS;
        }

        $promoted = $recompute->promotedByTierPass();

        if ($promoted === []) {
            $this->info('No tier promotions today.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Promoted %d member(s): %s', count($promoted), implode(', ', $promoted)));

        return self::SUCCESS;
    }
}
