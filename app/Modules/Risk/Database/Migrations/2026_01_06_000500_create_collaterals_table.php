<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pledged security. docs/03-domain/11-risk-credit.md §11.6.
 *
 * acceptance_factor_bps is stored per row rather than derived from the type, so
 * a haircut agreed with one member survives a change to the platform default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaterals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->enum('type', ['GOLD_VAULT', 'RIAL_DEPOSIT', 'BANK_GUARANTEE', 'THIRD_PARTY_GUARANTEE']);
            $table->enum('status', ['PENDING', 'ACTIVE', 'RELEASED', 'LIQUIDATED'])->default('PENDING');
            $table->unsignedBigInteger('nominal_value_rial');
            $table->unsignedBigInteger('fine_weight_mg')->nullable()->comment('set for GOLD_VAULT');
            $table->unsignedInteger('acceptance_factor_bps');
            $table->string('reference', 191)->nullable();
            $table->timestamp('valued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'idx_org_status');
            $table->index('expires_at', 'idx_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaterals');
    }
};
