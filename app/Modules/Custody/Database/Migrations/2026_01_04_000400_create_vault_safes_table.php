<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Safes inside a vault — docs/03-domain/06-custody-vault.md §6.1. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_safes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vault_id');
            $table->string('safe_code', 10);        // S03
            $table->string('full_code', 50);        // V01-S03
            $table->string('type', 50)->nullable();
            $table->enum('security_level', ['LOW', 'MEDIUM', 'HIGH'])->default('HIGH');
            $table->enum('status', ['ACTIVE', 'SEALED', 'DISABLED'])->default('ACTIVE');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('full_code', 'uq_safe_full_code');
            $table->unique(['vault_id', 'safe_code'], 'uq_safe_in_vault');

            $table->foreign('vault_id', 'fk_safe_vault')
                ->references('id')->on('vaults')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_safes');
    }
};
