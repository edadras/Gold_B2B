<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * gold_lots — docs/04-data/02-schema-mysql.md §2.3.
 *
 * Every physical piece of metal in the system is one row here. Ownership
 * (owner_organization_id) is deliberately independent of custody
 * (custodian_type/custodian_id): a trade moves the former without touching
 * the latter.
 *
 * No FKs to organizations/users: Identity owns those tables and this module
 * must migrate independently of it (AGENT_BRIEF rule 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gold_lots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('lot_code', 20);                       // GL-00001287
            $table->enum('metal_type', ['GOLD', 'SILVER', 'PLATINUM'])->default('GOLD');

            // ── weight & purity ──────────────────────────────────────────
            $table->unsignedBigInteger('gross_weight_mg');
            $table->unsignedSmallInteger('purity_x10');           // 0..10000
            $table->unsignedBigInteger('fine_weight_mg');
            $table->enum('purity_source', ['ASSAYED', 'DECLARED', 'ESTIMATED']);

            // ── physical identity ────────────────────────────────────────
            $table->string('serial_number', 50)->nullable();
            $table->string('hallmark_code', 50)->nullable();
            $table->enum('shape', ['BAR', 'GRAIN', 'SCRAP', 'COIN', 'OTHER']);
            $table->char('qr_token', 64);

            // ── origin ───────────────────────────────────────────────────
            $table->enum('origin_type', [
                'MELT', 'IMPORT', 'MEMBER_DEPOSIT', 'SPLIT', 'MERGE', 'REASSAY',
            ]);
            $table->unsignedBigInteger('refiner_id')->nullable();
            $table->timestamp('refined_at')->nullable();
            $table->unsignedBigInteger('current_assay_id')->nullable();
            $table->unsignedSmallInteger('generation')->default(1);

            // ── ownership (independent of custody) ───────────────────────
            $table->unsignedBigInteger('owner_organization_id');

            // ── custody ──────────────────────────────────────────────────
            $table->enum('custodian_type', [
                'VAULT', 'ORGANIZATION', 'LAB', 'IN_TRANSIT', 'THIRD_PARTY',
            ]);
            $table->unsignedBigInteger('custodian_id');
            $table->unsignedBigInteger('vault_box_id')->nullable();
            $table->string('physical_location', 50)->nullable();  // V01-S03-F02-B14

            // ── status ───────────────────────────────────────────────────
            $table->enum('status', [
                'UNDER_ASSAY', 'AVAILABLE', 'RESERVED', 'IN_SETTLEMENT',
                'IN_TRANSIT', 'ON_HOLD', 'WITHDRAWN', 'CONSUMED',
            ]);
            $table->string('hold_reason', 500)->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('lot_code', 'uq_lot_code');
            $table->unique('qr_token', 'uq_qr_token');
            $table->index(['owner_organization_id', 'status'], 'idx_owner_status');
            $table->index(
                ['owner_organization_id', 'status', 'custodian_type', 'purity_x10'],
                'idx_allocation'
            );
            $table->index(['custodian_type', 'custodian_id'], 'idx_custodian');
            $table->index('vault_box_id', 'idx_box');
            $table->index('serial_number', 'idx_serial');
        });

        // MariaDB/MySQL 8 CHECK constraints. These are the last line of defence
        // for the invariants the domain layer already enforces.
        DB::statement('ALTER TABLE gold_lots ADD CONSTRAINT chk_purity_range CHECK (purity_x10 <= 10000)');
        DB::statement('ALTER TABLE gold_lots ADD CONSTRAINT chk_fine_le_gross CHECK (fine_weight_mg <= gross_weight_mg)');
        DB::statement('ALTER TABLE gold_lots ADD CONSTRAINT chk_weights_positive CHECK (gross_weight_mg > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_lots');
    }
};
