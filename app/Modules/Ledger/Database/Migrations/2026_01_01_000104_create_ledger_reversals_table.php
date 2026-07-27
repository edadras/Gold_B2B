<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/04-data/02-schema-mysql.md §2.2 — ledger_reversals.
 *
 * This table is the whole reason ledger_entries stays strictly append-only.
 * docs/03-domain/03-ledger.md §3.6 weighs writing reversed_by_entry_id back
 * onto the original row (option ب) against a separate link table (option الف)
 * and decides on الف, so the link lives here instead of as an UPDATE.
 *
 * UNIQUE(original_entry_id) is invariant I8 in the schema: an entry can be
 * reversed at most once, even if two operators race.
 * UNIQUE(reversal_entry_id) stops one reversal row being claimed twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_reversals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('original_entry_id');
            $table->unsignedBigInteger('reversal_entry_id');
            $table->string('reason', 500);
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('original_entry_id', 'uq_original');
            $table->unique('reversal_entry_id', 'uq_reversal');

            $table->foreign('original_entry_id', 'fk_reversal_original')
                ->references('id')->on('ledger_entries')
                ->restrictOnDelete();
            $table->foreign('reversal_entry_id', 'fk_reversal_reversal')
                ->references('id')->on('ledger_entries')
                ->restrictOnDelete();
        });

        // Four-eyes rule: a reversal may never be requested and approved by the
        // same person (docs/03-domain/03-ledger.md §3.4, MANUAL_ADJUSTMENT).
        DB::statement(
            'ALTER TABLE ledger_reversals ADD CONSTRAINT chk_different_approver'
            .' CHECK (requested_by_user_id <> approved_by_user_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_reversals');
    }
};
