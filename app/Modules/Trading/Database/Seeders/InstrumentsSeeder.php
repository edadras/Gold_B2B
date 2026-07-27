<?php

declare(strict_types=1);

namespace App\Modules\Trading\Database\Seeders;

use App\Modules\Trading\Domain\InstrumentStatus;
use App\Modules\Trading\Domain\SettlementType;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use Illuminate\Database\Seeder;

/**
 * The three phase-2 instruments of docs/04-data/02-schema-mysql.md §2.7.
 *
 * Idempotent by `code`: the seeder may run on every deploy, and re-running it
 * must not duplicate a row or reset an instrument an operator has paused. Only
 * missing codes are inserted; existing rows are left exactly as they are.
 *
 * Note the two order minimums: GOLD-750-T0 asks for 100 g rather than 50 g,
 * because low-purity melted gold trades in larger parcels.
 */
final class InstrumentsSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    private const INSTRUMENTS = [
        [
            'code' => 'GOLD-995-T0',
            'name' => 'آب‌شده ۹۹۵ نقدی',
            'min_purity_x10' => 9950,
            'settlement_type' => SettlementType::T0,
            'min_order_mg' => 50_000,
        ],
        [
            'code' => 'GOLD-995-T1',
            'name' => 'آب‌شده ۹۹۵ فردایی',
            'min_purity_x10' => 9950,
            'settlement_type' => SettlementType::T1,
            'min_order_mg' => 50_000,
        ],
        [
            'code' => 'GOLD-750-T0',
            'name' => 'آب‌شده ۷۵۰ نقدی',
            'min_purity_x10' => 7500,
            'settlement_type' => SettlementType::T0,
            'min_order_mg' => 100_000,
        ],
    ];

    public function run(): void
    {
        foreach (self::INSTRUMENTS as $definition) {
            Instrument::query()->firstOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'metal_type' => 'GOLD',
                    'min_purity_x10' => $definition['min_purity_x10'],
                    'quote_unit' => 'GRAM_FINE',
                    'settlement_type' => $definition['settlement_type'],
                    'tick_size_rial' => 10_000,
                    'lot_size_mg' => 1_000,
                    'min_order_mg' => $definition['min_order_mg'],
                    'max_order_mg' => 50_000_000,
                    'max_price_deviation_bps' => 1_000,
                    'status' => InstrumentStatus::ACTIVE,
                ],
            );
        }
    }
}
