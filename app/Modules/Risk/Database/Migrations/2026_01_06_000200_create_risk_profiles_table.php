<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per member. docs/03-domain/11-risk-credit.md §11.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->unique();
            $table->enum('risk_level', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->default('MEDIUM');
            $table->unsignedSmallInteger('credit_score')->default(0);

            // Trading ceilings.
            $table->unsignedBigInteger('max_order_mg')->default(0);
            $table->unsignedBigInteger('max_daily_volume_mg')->default(0);
            $table->unsignedInteger('max_open_orders')->default(0);
            $table->unsignedBigInteger('max_open_exposure_mg')->default(0);
            $table->unsignedBigInteger('max_open_exposure_rial')->default(0);

            // Credit.
            $table->unsignedBigInteger('unsecured_credit_mg')->default(0);
            $table->unsignedBigInteger('collateral_value_rial')->default(0);

            // Settlement.
            $table->json('allowed_settlement_types');
            $table->unsignedTinyInteger('max_settlement_days')->default(0);

            // Status.
            $table->boolean('is_trading_allowed')->default(true);
            $table->string('restriction_reason', 200)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamps();

            $table->index(['risk_level', 'is_trading_allowed'], 'idx_level_allowed');
            $table->index('next_review_at', 'idx_next_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_profiles');
    }
};
