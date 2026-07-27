<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/09-accounting.md §9.2 — journal_entries.
 *
 * `uq_source` is not merely a data-quality index: it IS the idempotency
 * mechanism for event-driven posting (§9.7). Vouchers are produced on the
 * `accounting` queue from domain events, and a queue redelivers. The second
 * attempt collides with this key, JournalPoster catches the collision and
 * returns the voucher that already exists, so a duplicated event cannot
 * double-post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            // Sequential per organisation and year, no gaps (§9.7).
            $table->string('voucher_no', 30);
            $table->date('entry_date');
            $table->string('description', 500);

            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');

            $table->enum('status', ['DRAFT', 'POSTED', 'REVERSED']);
            $table->timestamp('posted_at')->nullable();

            // Points at the voucher this one reverses; deletion is forbidden.
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->unsignedBigInteger('reversed_by_id')->nullable();

            // Denormalised control totals. Both invariants of §9.2 are asserted
            // before insert; storing them lets a reconciliation job re-check the
            // sums against journal_lines without recomputing the whole journal.
            $table->unsignedBigInteger('total_rial')->default(0);
            $table->unsignedBigInteger('total_fine_mg')->default(0);

            $table->unsignedBigInteger('accounting_period_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organization_id', 'voucher_no'], 'uq_org_voucher');
            $table->unique(['organization_id', 'source_type', 'source_id'], 'uq_source');
            $table->index(['organization_id', 'entry_date'], 'idx_org_date');
            $table->index(['source_type', 'source_id'], 'idx_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
