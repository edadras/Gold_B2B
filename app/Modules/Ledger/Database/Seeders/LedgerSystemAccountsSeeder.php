<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Database\Seeders;

use App\Modules\Ledger\Application\AccountProvisioner;
use Illuminate\Database\Seeder;

/**
 * Seeds the internal accounts of docs/04-data/02-schema-mysql.md §2.7 under
 * organization_id = 0.
 *
 * These are not optional: without them a deposit, a fee or a rounding
 * difference has nowhere to put its counter-leg, and invariant I4 (Σ per asset
 * across the system == 0) cannot hold. Safe to re-run.
 */
final class LedgerSystemAccountsSeeder extends Seeder
{
    public function __construct(private readonly AccountProvisioner $provisioner) {}

    public function run(): void
    {
        $ids = $this->provisioner->provisionSystemAccounts();

        if ($this->command !== null) {
            $this->command->info(sprintf('Ledger system accounts ready (%d accounts).', count($ids)));
        }
    }
}
