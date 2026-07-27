<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vaults — docs/03-domain/06-custody-vault.md §6.1 and §6.7 (insurance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaults', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('vault_code', 10);              // V01
            $table->string('name', 191);
            $table->string('address', 500)->nullable();
            $table->unsignedBigInteger('operator_organization_id')->nullable();
            $table->unsignedBigInteger('capacity_fine_mg')->nullable();

            // Insurance — §6.7
            $table->string('insurance_policy_no', 100)->nullable();
            $table->string('insurer_name', 191)->nullable();
            $table->unsignedBigInteger('coverage_amount_rial')->nullable();
            $table->date('coverage_expires_at')->nullable();
            $table->text('liability_terms')->nullable();

            $table->enum('status', ['ACTIVE', 'SUSPENDED', 'FROZEN', 'CLOSED'])->default('ACTIVE');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('vault_code', 'uq_vault_code');
            $table->index('status', 'idx_vault_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaults');
    }
};
