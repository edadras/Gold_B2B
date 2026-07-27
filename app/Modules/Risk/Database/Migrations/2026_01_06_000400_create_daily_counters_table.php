<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable history behind the Redis counters of §11.5. Redis answers the
 * pre-trade check; this table answers "what did they do last Tuesday".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_counters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->date('counter_date')->comment('market timezone, not UTC');
            $table->unsignedBigInteger('volume_mg')->default(0);
            $table->unsignedBigInteger('value_rial')->default(0);
            $table->unsignedInteger('trade_count')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'counter_date'], 'uq_org_date');
            $table->index('counter_date', 'idx_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_counters');
    }
};
