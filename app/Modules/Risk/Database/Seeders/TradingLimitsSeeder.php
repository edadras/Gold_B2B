<?php

declare(strict_types=1);

namespace App\Modules\Risk\Database\Seeders;

use App\Modules\Risk\Domain\RiskLevel;
use App\Modules\Risk\Infrastructure\Models\TradingLimit;
use Illuminate\Database\Seeder;

/** The default ceilings table of docs/03-domain/11-risk-credit.md §11.1. */
final class TradingLimitsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RiskLevel::cases() as $level) {
            TradingLimit::query()->updateOrCreate(
                ['risk_level' => $level->value],
                $level->defaults(),
            );
        }
    }
}
