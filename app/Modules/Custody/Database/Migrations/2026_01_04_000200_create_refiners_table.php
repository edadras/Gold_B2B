<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refiners — docs/03-domain/02-gold-lot-assay.md §2.9.
 *
 * average_loss_bps is the rolling melt loss used to predict the yield of a
 * MELT operation before it happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refiners', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('license_no', 100);
            $table->string('address', 500)->nullable();
            $table->unsignedInteger('average_loss_bps')->default(0);
            $table->unsignedBigInteger('total_processed_mg')->default(0);
            $table->json('variance_history')->nullable();
            $table->enum('status', ['ACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('license_no', 'uq_refiner_license');
            $table->index('status', 'idx_refiner_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refiners');
    }
};
