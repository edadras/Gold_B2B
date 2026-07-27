<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boxes inside a shelf — the leaf of the location hierarchy that a GoldLot
 * actually points at. docs/03-domain/06-custody-vault.md §6.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_boxes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vault_id');
            $table->unsignedBigInteger('vault_safe_id');
            $table->unsignedBigInteger('vault_shelf_id');
            $table->string('box_code', 10);         // B14
            $table->string('full_code', 50);        // V01-S03-F02-B14
            $table->unsignedBigInteger('capacity_gross_mg')->nullable();
            $table->enum('status', ['ACTIVE', 'FULL', 'SEALED', 'DISABLED'])->default('ACTIVE');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('full_code', 'uq_box_full_code');
            $table->unique(['vault_shelf_id', 'box_code'], 'uq_box_in_shelf');
            $table->index(['vault_id', 'status'], 'idx_box_vault');

            $table->foreign('vault_shelf_id', 'fk_box_shelf')
                ->references('id')->on('vault_shelves')->restrictOnDelete();
            $table->foreign('vault_id', 'fk_box_vault')
                ->references('id')->on('vaults')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_boxes');
    }
};
