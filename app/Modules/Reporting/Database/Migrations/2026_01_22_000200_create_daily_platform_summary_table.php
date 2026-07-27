<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform-side rollup behind the management reports of §15.6
 * («حجم و ارزش معاملات»، «توزیع اعضا و فعالیت»، «درآمد کارمزد»).
 *
 * Derived from daily_org_summary rather than from the ledger a second time, so
 * the platform view and the member views can never disagree: if a member's day
 * did not reconcile, the platform row for that day says so too through
 * `unreconciled_orgs`, instead of quietly averaging the error away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_platform_summary', function (Blueprint $table): void {
            $table->date('summary_date')->primary();

            $table->unsignedInteger('active_org_count')->default(0);
            $table->unsignedInteger('trading_org_count')->default(0);

            $table->unsignedBigInteger('gold_traded_mg')->default(0);
            $table->unsignedBigInteger('rial_traded')->default(0);
            $table->unsignedInteger('trade_count')->default(0);

            $table->unsignedBigInteger('gold_deposited_mg')->default(0);
            $table->unsignedBigInteger('gold_withdrawn_mg')->default(0);
            $table->bigInteger('gold_held_mg')->default(0);

            $table->unsignedBigInteger('fee_income_rial')->default(0);

            $table->unsignedInteger('settlement_count')->default(0);
            $table->unsignedInteger('default_count')->default(0);
            $table->unsignedInteger('dispute_opened_count')->default(0);

            // How many member rows failed their own invariant that day.
            $table->unsignedInteger('unreconciled_orgs')->default(0);
            $table->boolean('is_reconciled')->default(false);

            $table->timestamp('computed_at');

            $table->index('is_reconciled', 'idx_reconciled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_platform_summary');
    }
};
