<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Console;

use App\Modules\Kyc\Application\LicenseExpiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily sweep of the licence reminder ladder and the auto-restriction on expiry
 * (docs/03-domain/01-identity-kyc.md §1.6).
 *
 * Safe to run repeatedly: LicenseExpiryService tracks which rungs have already
 * fired per licence.
 */
final class CheckExpiringLicensesCommand extends Command
{
    protected $signature = 'kyc:check-expiring-licenses
                            {--as-of= : Evaluate as if today were this date (YYYY-MM-DD), for testing}
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'Send business licence expiry reminders (30/10/1 days) and restrict members whose licence has lapsed';

    public function handle(LicenseExpiryService $service): int
    {
        $asOf = $this->option('as-of') !== null
            ? Carbon::parse((string) $this->option('as-of'))
            : Carbon::now();

        if ((bool) $this->option('dry-run')) {
            $this->warn('Dry run: no reminders sent, no members restricted.');
            $this->info('Evaluating as of '.$asOf->toDateString());

            return self::SUCCESS;
        }

        $result = $service->run($asOf);

        $this->info(sprintf(
            'Licence sweep as of %s — reminders: %d, expired: %d, members restricted: %d',
            $asOf->toDateString(),
            $result['reminders'],
            $result['expired'],
            $result['restricted'],
        ));

        return self::SUCCESS;
    }
}
