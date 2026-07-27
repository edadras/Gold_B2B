<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member price alerts. docs/03-domain/07-pricing.md §7.9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('instrument_id');
            $table->enum('condition', ['ABOVE', 'BELOW', 'CHANGE_PERCENT']);
            $table->bigInteger('threshold')->comment('rial for ABOVE/BELOW, bps for CHANGE_PERCENT');
            $table->unsignedInteger('window_seconds')->default(3600)
                ->comment('CHANGE_PERCENT lookback window');
            $table->boolean('is_recurring')->default(false);
            $table->enum('status', ['ACTIVE', 'TRIGGERED', 'DISABLED'])->default('ACTIVE');
            $table->timestamp('triggered_at', 3)->nullable();
            $table->timestamps();

            $table->index(['instrument_id', 'status'], 'idx_instrument_status');
            $table->index(['organization_id', 'status'], 'idx_org_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_alerts');
    }
};
