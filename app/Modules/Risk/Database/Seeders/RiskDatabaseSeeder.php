<?php

declare(strict_types=1);

namespace App\Modules\Risk\Database\Seeders;

use Illuminate\Database\Seeder;

/** Everything the Risk module needs in a fresh database. */
final class RiskDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TradingLimitsSeeder::class,
            AmlRulesSeeder::class,
        ]);
    }
}
