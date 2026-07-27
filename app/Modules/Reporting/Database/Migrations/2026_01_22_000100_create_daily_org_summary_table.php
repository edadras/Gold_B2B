<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/15-notification-reporting.md §15.9 — daily_org_summary.
 *
 * A nightly rollup so the common reports do not re-aggregate the ledger.
 *
 * `is_reconciled` carries the invariant of §15.9:
 *
 *     gold_closing = gold_opening + bought + deposited
 *                  − sold − withdrawn + adjustment
 *
 * The row is written either way — a summary that silently vanished when it
 * failed to balance would hide exactly the day an operator needs to look at —
 * but the flag says whether the arithmetic held.
 *
 * PARTITIONING: production partitions this by RANGE on summary_date. Not
 * applied locally, per ADR-012 and the AGENT_BRIEF environment note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_org_summary', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->date('summary_date');

            // Signed: an organisation can be short after a correction.
            $table->bigInteger('gold_opening_mg')->default(0);
            $table->unsignedBigInteger('gold_bought_mg')->default(0);
            $table->unsignedBigInteger('gold_sold_mg')->default(0);
            $table->unsignedBigInteger('gold_deposited_mg')->default(0);
            $table->unsignedBigInteger('gold_withdrawn_mg')->default(0);
            $table->bigInteger('gold_adjustment_mg')->default(0);
            $table->bigInteger('gold_closing_mg')->default(0);

            $table->bigInteger('rial_opening')->default(0);
            $table->unsignedBigInteger('rial_in')->default(0);
            $table->unsignedBigInteger('rial_out')->default(0);
            $table->unsignedBigInteger('rial_fees')->default(0);
            $table->bigInteger('rial_closing')->default(0);

            $table->unsignedInteger('trade_count')->default(0);
            $table->unsignedInteger('buy_count')->default(0);
            $table->unsignedInteger('sell_count')->default(0);

            $table->bigInteger('realized_pnl')->default(0);
            $table->unsignedBigInteger('avg_cost_per_gram')->default(0);
            $table->unsignedBigInteger('closing_market_value')->default(0);

            $table->boolean('is_reconciled')->default(false);
            // Signed difference when it did not reconcile, so the size and
            // direction of the error survive without re-running the day.
            $table->bigInteger('gold_discrepancy_mg')->default(0);
            $table->bigInteger('rial_discrepancy')->default(0);

            $table->timestamp('computed_at');

            $table->primary(['organization_id', 'summary_date']);
            $table->index(['summary_date', 'is_reconciled'], 'idx_date_reconciled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_org_summary');
    }
};
