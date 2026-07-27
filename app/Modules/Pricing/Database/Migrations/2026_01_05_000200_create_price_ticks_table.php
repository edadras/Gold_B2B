<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every observation we received, accepted or not. docs/03-domain/07-pricing.md §7.3.
 *
 * ADR-012: in production this table is RANGE-partitioned on received_at, which is
 * why received_at is part of the primary key. The PARTITION BY clause is
 * deliberately omitted here — partitioning is applied by an operations migration,
 * not by the application schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_ticks', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedBigInteger('source_id');
            $table->enum('price_type', ['OUNCE_USD', 'USD_IRR', 'MESGHAL_IRR', 'FINE_GRAM_IRR']);
            $table->bigInteger('value');
            $table->unsignedTinyInteger('scale')->default(0)->comment('decimal digits held in value');
            $table->timestamp('observed_at', 3)->comment('timestamp claimed by the source');
            $table->timestamp('received_at', 3)->comment('timestamp we stored it');
            $table->boolean('is_accepted')->default(true);
            $table->string('rejection_reason', 200)->nullable();
            $table->boolean('is_cross_source_outlier')->default(false)
                ->comment('filter 3: disagreed with the median of peer sources');
            $table->bigInteger('effective_value')->nullable()
                ->comment('median substituted for value when cross-source flagged');

            $table->primary(['id', 'received_at']);
            $table->index(['price_type', 'received_at'], 'idx_type_time');
            $table->index(['source_id', 'observed_at'], 'idx_source_observed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_ticks');
    }
};
