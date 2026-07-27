<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External price feeds, ordered by priority. docs/03-domain/07-pricing.md §7.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 191);
            $table->enum('type', ['OUNCE', 'FX', 'MESGHAL', 'MANUAL']);
            $table->enum('price_type', ['OUNCE_USD', 'USD_IRR', 'MESGHAL_IRR', 'FINE_GRAM_IRR']);
            $table->unsignedTinyInteger('priority')->comment('1 = primary');
            $table->string('driver', 50)->default('manual');
            $table->string('endpoint', 500)->nullable();
            $table->unsignedInteger('max_staleness_s')->default(300);
            $table->unsignedInteger('max_deviation_bps')->default(500);
            $table->unsignedBigInteger('min_sane_value')->default(1);
            $table->unsignedBigInteger('max_sane_value');
            $table->enum('status', ['ACTIVE', 'DEGRADED', 'DOWN'])->default('ACTIVE');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_success_at', 3)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['price_type', 'priority'], 'idx_type_priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_sources');
    }
};
