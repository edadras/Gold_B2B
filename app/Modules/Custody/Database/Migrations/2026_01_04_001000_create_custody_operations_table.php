<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * custody_operations — docs/03-domain/06-custody-vault.md §6.5.
 *
 * Every physical or logical movement of metal produces exactly one row here.
 * The module-wide invariant is
 *
 *     input_fine_mg = output_fine_mg + loss_fine_mg
 *
 * enforced in the application layer (it spans JSON columns, so a CHECK cannot
 * express it) and asserted by the conservation tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('operation_type', 30);
            $table->unsignedBigInteger('vault_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->json('input_lot_ids');
            $table->json('output_lot_ids')->nullable();
            $table->unsignedBigInteger('input_fine_mg');
            $table->unsignedBigInteger('output_fine_mg')->nullable();
            $table->bigInteger('loss_fine_mg')->default(0);
            $table->bigInteger('loss_gross_mg')->default(0);

            $table->string('from_location', 50)->nullable();
            $table->string('to_location', 50)->nullable();

            $table->string('reason', 500)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->unsignedBigInteger('executed_by_user_id')->nullable();

            $table->enum('status', [
                'REQUESTED', 'APPROVED', 'EXECUTING',
                'COMPLETED', 'REJECTED', 'CANCELLED',
            ]);
            $table->boolean('requires_extra_approval')->default(false);

            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->json('photos')->nullable();
            $table->json('signature_data')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['operation_type', 'status'], 'idx_op_type_status');
            $table->index(['vault_id', 'created_at'], 'idx_op_vault_time');
            $table->index(['organization_id', 'created_at'], 'idx_op_org_time');
            $table->index(['reference_type', 'reference_id'], 'idx_op_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_operations');
    }
};
