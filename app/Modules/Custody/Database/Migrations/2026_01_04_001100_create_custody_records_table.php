<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * custody_records — the chain of custody for one lot.
 *
 * Exactly one ACTIVE row per live lot; a movement closes the current row
 * (released_at) and opens a new one. This is what answers "who was holding
 * this piece of metal on date X" during a dispute.
 * docs/03-domain/06-custody-vault.md §6.2 / §6.4 step 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('gold_lot_id');

            $table->enum('custodian_type', [
                'VAULT', 'ORGANIZATION', 'LAB', 'IN_TRANSIT', 'THIRD_PARTY',
            ]);
            $table->unsignedBigInteger('custodian_id');
            $table->unsignedBigInteger('vault_id')->nullable();
            $table->unsignedBigInteger('vault_box_id')->nullable();
            $table->string('physical_location', 50)->nullable();

            $table->unsignedBigInteger('gross_weight_mg');
            $table->unsignedBigInteger('fine_weight_mg');

            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('received_operation_id')->nullable();
            $table->unsignedBigInteger('released_operation_id')->nullable();
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->unsignedBigInteger('released_by_user_id')->nullable();

            $table->enum('status', ['ACTIVE', 'CLOSED'])->default('ACTIVE');
            $table->string('notes', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['gold_lot_id', 'status'], 'idx_custody_lot');
            $table->index(['vault_id', 'status'], 'idx_custody_vault');
            $table->index(['custodian_type', 'custodian_id'], 'idx_custody_holder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_records');
    }
};
