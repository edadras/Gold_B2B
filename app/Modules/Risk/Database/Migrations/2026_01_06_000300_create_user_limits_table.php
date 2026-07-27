<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-operator ceilings inside a member. Check 10 of §11.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_limits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('max_order_mg');
            $table->unsignedBigInteger('max_daily_volume_mg');
            $table->unsignedBigInteger('requires_approval_above_mg')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active'], 'idx_org_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_limits');
    }
};
