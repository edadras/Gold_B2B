<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only movement log behind every relation balance.
 *
 * Not in the design doc's DDL, but required by two features that are:
 * the running statement of §10.3 (opening balance / movements / closing
 * balance with a reconciliation check) and the discrepancy view of §10.4,
 * which must list "the trades in the period" so the two sides can find where
 * they diverged. Rebuilding either from Trading/Settlement tables is not an
 * option here — Counterparty may depend only on Shared and Identity — so the
 * module keeps its own copy of the deltas it was told about.
 *
 * Written by RelationService in the same transaction as the balance upsert.
 * Rows are never updated or deleted; a correction is a new reversing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparty_movements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('counterparty_org_id');

            // Same sign convention as the relation: positive = in our favour.
            $table->bigInteger('gold_delta_mg');
            $table->bigInteger('rial_delta');

            $table->string('kind', 30);
            $table->string('reference', 64)->nullable();
            $table->string('description', 255)->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            // Statement queries walk one pair in chronological order; id breaks
            // ties so a statement is stable when two movements share a second.
            $table->index(
                ['organization_id', 'counterparty_org_id', 'occurred_at', 'id'],
                'idx_pair_occurred'
            );
            $table->index('reference', 'idx_movement_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_movements');
    }
};
