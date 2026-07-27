<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/04-data/02-schema-mysql.md §2.2 — ledger_entries.
 *
 * APPEND-ONLY. Nothing in the application may UPDATE or DELETE a row here
 * (AGENT_BRIEF rule 2); corrections are new reversing rows plus a
 * ledger_reversals link row, per the decision in docs/03-domain/03-ledger.md
 * §3.6 (option الف).
 *
 * PARTITIONING: production partitions this table BY RANGE (UNIX_TIMESTAMP(created_at))
 * and therefore needs the composite PRIMARY KEY (id, created_at). That is
 * deliberately NOT applied here — see ADR-012 and AGENT_BRIEF "Environment":
 * partitioning is a production/ops concern and the composite key would prevent
 * ledger_reversals from referencing id alone. Locally the key is PRIMARY KEY (id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('organization_id');   // denormalised for speed
            $table->enum('asset_type', ['GOLD', 'RIAL']);

            // Signed. Positive = credit, negative = debit.
            // GOLD: milligrams of pure gold. RIAL: rial.
            $table->bigInteger('amount');

            $table->string('entry_type', 50);
            $table->enum('direction', ['CREDIT', 'DEBIT']);

            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->char('transaction_group', 36);

            // Rebuildable cache of Σ(amount) on this account up to this row (I9).
            $table->bigInteger('balance_after');

            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            // Tamper-evidence chain, per account (I10).
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);

            $table->index(['account_id', 'created_at'], 'idx_account_time');
            $table->index(['organization_id', 'created_at'], 'idx_org_time');
            $table->index(['reference_type', 'reference_id'], 'idx_reference');
            $table->index('transaction_group', 'idx_group');
            $table->index(['entry_type', 'created_at'], 'idx_type_time');
            // Walking one account's hash chain in write order.
            $table->index(['account_id', 'id'], 'idx_account_id_seq');

            $table->foreign('account_id', 'fk_entry_account')
                ->references('id')->on('ledger_accounts')
                ->restrictOnDelete();
        });

        // A zero-amount entry carries no information and would break direction.
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_amount_not_zero CHECK (amount <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
