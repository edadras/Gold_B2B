<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default ceilings per risk level. docs/03-domain/11-risk-credit.md §11.1.
 *
 * A member's risk_profiles row is seeded from here and may then be adjusted
 * individually, so operators can retune the defaults without rewriting code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_limits', function (Blueprint $table): void {
            $table->id();
            $table->enum('risk_level', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->unique();
            $table->unsignedBigInteger('max_order_mg');
            $table->unsignedBigInteger('max_daily_volume_mg');
            $table->unsignedInteger('max_open_orders');
            $table->unsignedBigInteger('max_open_exposure_mg');
            $table->unsignedBigInteger('max_open_exposure_rial');
            $table->unsignedBigInteger('unsecured_credit_mg');
            $table->json('allowed_settlement_types');
            $table->unsignedTinyInteger('max_settlement_days');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_limits');
    }
};
