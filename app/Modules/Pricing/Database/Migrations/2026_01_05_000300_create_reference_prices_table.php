<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intrinsic value snapshots (F6). docs/03-domain/07-pricing.md §7.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instrument_id');
            $table->unsignedBigInteger('fine_gram_rial');
            $table->unsignedBigInteger('ounce_usd_micro');
            $table->unsignedBigInteger('usd_irr');
            $table->timestamp('computed_at', 3);
            $table->json('source_tick_ids');
            $table->enum('mode', ['PRIMARY', 'FALLBACK', 'MANUAL'])->default('PRIMARY');

            $table->index(['instrument_id', 'computed_at'], 'idx_instrument_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_prices');
    }
};
