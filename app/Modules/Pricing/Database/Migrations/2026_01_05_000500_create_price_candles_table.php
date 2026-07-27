<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OHLCV buckets built from ORDER_BOOK trades. docs/03-domain/07-pricing.md §7.7.
 *
 * ADR-012: candidate for RANGE partitioning on opened_at in production; the
 * PARTITION BY clause is intentionally not part of the application schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_candles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instrument_id');
            $table->enum('interval_code', ['1m', '5m', '15m', '1h', '1d']);
            $table->timestamp('opened_at');
            $table->unsignedBigInteger('open_price');
            $table->unsignedBigInteger('high_price');
            $table->unsignedBigInteger('low_price');
            $table->unsignedBigInteger('close_price');
            $table->unsignedBigInteger('volume_mg');
            $table->unsignedInteger('trade_count');

            $table->unique(['instrument_id', 'interval_code', 'opened_at'], 'uq_inst_interval_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_candles');
    }
};
