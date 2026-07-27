<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/09-accounting.md §9.7 — «دوره مالی قابل تنظیم به تفکیک عضو».
 *
 * A member's fiscal year need not end in اسفند, so periods are per organisation
 * and stored as explicit date ranges rather than derived from a calendar rule.
 * Once CLOSED, nothing may be posted into the range again; corrections go into
 * whichever period is currently OPEN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->string('period_code', 20);     // e.g. 1404-08
            $table->date('starts_on');
            $table->date('ends_on');

            $table->enum('status', ['OPEN', 'CLOSED'])->default('OPEN');

            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();

            // Snapshot of the trial balance at close, so a later report can be
            // checked against what the numbers were when the books were shut.
            $table->bigInteger('closing_debit_rial')->default(0);
            $table->bigInteger('closing_credit_rial')->default(0);
            $table->bigInteger('closing_fine_mg')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'period_code'], 'uq_org_period');
            $table->index(['organization_id', 'starts_on', 'ends_on'], 'idx_org_range');
            $table->index(['organization_id', 'status'], 'idx_org_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
