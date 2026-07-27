<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shelves inside a safe — docs/03-domain/06-custody-vault.md §6.1. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_shelves', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vault_id');
            $table->unsignedBigInteger('vault_safe_id');
            $table->string('shelf_code', 10);       // F02
            $table->string('full_code', 50);        // V01-S03-F02
            $table->enum('status', ['ACTIVE', 'SEALED', 'DISABLED'])->default('ACTIVE');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('full_code', 'uq_shelf_full_code');
            $table->unique(['vault_safe_id', 'shelf_code'], 'uq_shelf_in_safe');

            $table->foreign('vault_safe_id', 'fk_shelf_safe')
                ->references('id')->on('vault_safes')->restrictOnDelete();
            $table->foreign('vault_id', 'fk_shelf_vault')
                ->references('id')->on('vaults')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_shelves');
    }
};
