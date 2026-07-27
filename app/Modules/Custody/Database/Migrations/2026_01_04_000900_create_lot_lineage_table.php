<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * lot_lineage — the parent/child graph that is never deleted.
 * docs/03-domain/02-gold-lot-assay.md §2.7, schema §2.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_lineage', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_lot_id');
            $table->unsignedBigInteger('child_lot_id');
            $table->enum('operation', ['SPLIT', 'MERGE', 'MELT', 'REASSAY']);
            $table->unsignedBigInteger('operation_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['parent_lot_id', 'child_lot_id'], 'uq_parent_child');
            $table->index('child_lot_id', 'idx_child');
        });

        // A lot can never be its own parent — cheapest possible cycle guard.
        DB::statement('ALTER TABLE lot_lineage ADD CONSTRAINT chk_no_self_parent CHECK (parent_lot_id <> child_lot_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_lineage');
    }
};
