<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vault_waybills — the exit permit issued at step 7 of the withdrawal flow.
 * docs/03-domain/06-custody-vault.md §6.4.
 *
 * The one-time code is only ever stored hashed; the plaintext is returned once
 * to the caller so it can be sent to the owner's mobile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_waybills', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('waybill_no', 30);
            $table->unsignedBigInteger('custody_operation_id');
            $table->unsignedBigInteger('vault_id');
            $table->unsignedBigInteger('owner_organization_id');
            $table->json('gold_lot_ids');

            $table->string('receiver_name', 191)->nullable();
            $table->char('receiver_national_id_hash', 64)->nullable();

            $table->char('one_time_code_hash', 64);
            $table->timestamp('code_expires_at');
            $table->timestamp('code_used_at')->nullable();
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->char('qr_token', 64);

            $table->enum('status', ['ISSUED', 'USED', 'EXPIRED', 'CANCELLED'])
                ->default('ISSUED');

            $table->unsignedBigInteger('issued_by_user_id');
            $table->timestamp('issued_at')->useCurrent();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('waybill_no', 'uq_waybill_no');
            $table->unique('custody_operation_id', 'uq_waybill_operation');
            $table->unique('qr_token', 'uq_waybill_qr');
            $table->index(['vault_id', 'status'], 'idx_waybill_vault');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_waybills');
    }
};
