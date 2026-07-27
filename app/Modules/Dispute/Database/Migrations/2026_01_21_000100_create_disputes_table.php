<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/13-dispute.md §13.5 — disputes.
 *
 * `claim_gold_mg` / `claim_rial` hold ONLY the disputed quantity, never the
 * trade total: §13.4 is explicit that a 39-billion-rial trade with a
 * 394-million-rial purity claim locks 394 million. `hold_gold_entry_id` and
 * `hold_rial_entry_id` are the ledger entries that put it on hold, kept so the
 * release can name exactly what it is releasing.
 *
 * `awarded_gold_mg` and `awarded_rial` are SIGNED — an award can run either
 * way, and SPLIT_LIABILITY runs both ways at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('case_number', 30)->unique();   // DSP-1404-00142
            $table->string('dispute_type', 50);

            $table->unsignedBigInteger('trade_id')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->unsignedBigInteger('gold_lot_id')->nullable();

            $table->unsignedBigInteger('claimant_org_id');
            $table->unsignedBigInteger('respondent_org_id');
            $table->unsignedBigInteger('opened_by_user_id');

            $table->text('claim_description');
            $table->unsignedBigInteger('claim_gold_mg')->default(0);
            $table->unsignedBigInteger('claim_rial')->default(0);

            // How the disputed figure was derived, for the timeline and the UI.
            $table->string('claim_basis', 255)->nullable();

            $table->string('status', 30);
            $table->enum('priority', ['LOW', 'NORMAL', 'HIGH', 'URGENT'])->default('NORMAL');

            $table->unsignedBigInteger('hold_gold_entry_id')->nullable();
            $table->unsignedBigInteger('hold_rial_entry_id')->nullable();
            $table->boolean('hold_released')->default(false);

            $table->timestamp('reply_deadline_at')->nullable();
            $table->timestamp('negotiation_deadline_at')->nullable();

            $table->unsignedBigInteger('mediator_user_id')->nullable();
            $table->string('decision', 50)->nullable();
            $table->text('decision_rationale')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->boolean('is_frivolous')->default(false);

            $table->bigInteger('awarded_gold_mg')->default(0);   // signed
            $table->bigInteger('awarded_rial')->default(0);      // signed
            $table->unsignedBigInteger('reversal_settlement_id')->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['claimant_org_id', 'status'], 'idx_claimant');
            $table->index(['respondent_org_id', 'status'], 'idx_respondent');
            $table->index(['status', 'priority'], 'idx_status_priority');
            $table->index('trade_id', 'idx_trade');
            $table->index(['status', 'reply_deadline_at'], 'idx_reply_deadline');
            $table->index(['status', 'negotiation_deadline_at'], 'idx_negotiation_deadline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
