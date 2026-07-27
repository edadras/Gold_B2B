<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live top-of-book and session statistics per instrument.
 * docs/03-domain/07-pricing.md §7.3 / §7.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('instrument_id')->primary();
            $table->unsignedBigInteger('best_bid')->nullable();
            $table->unsignedBigInteger('best_bid_qty_mg')->nullable();
            $table->unsignedBigInteger('best_ask')->nullable();
            $table->unsignedBigInteger('best_ask_qty_mg')->nullable();
            $table->unsignedBigInteger('last_price')->nullable()
                ->comment('ORDER_BOOK trades only — OTC must never move LAST (§7.6)');
            $table->unsignedBigInteger('last_qty_mg')->nullable();
            $table->timestamp('last_at', 3)->nullable();
            $table->boolean('last_is_stale')->default(false);
            $table->unsignedBigInteger('day_open')->nullable();
            $table->unsignedBigInteger('day_high')->nullable();
            $table->unsignedBigInteger('day_low')->nullable();
            $table->unsignedBigInteger('day_volume_mg')->default(0);
            $table->unsignedBigInteger('day_vwap')->nullable();
            $table->unsignedBigInteger('day_vwap_numerator')->default(0)
                ->comment('running sum of price*qty so VWAP (F22) stays exact integer maths');
            $table->unsignedInteger('day_trade_count')->default(0);
            $table->date('session_date')->nullable();
            $table->timestamp('updated_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_quotes');
    }
};
