<?php

declare(strict_types=1);

use App\Modules\Reputation\Domain\VerificationTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cumulative public performance statistics (docs/03-domain/14-reputation.md §14.5).
 *
 * One row per organisation, keyed on the organisation id itself so the
 * accumulating upsert in StatsUpdater has a natural conflict target.
 *
 * Deviations from the doc's DDL, both deliberate:
 *  - three KYC/bank booleans, because the tier rules of §14.4 need them and
 *    reaching into the Kyc module's tables from here is not allowed; they are
 *    fed by string-named domain events and carry no KYC content;
 *  - `promotion_locked_until`, which implements the 90-day waiting period §14.4
 *    imposes after a demotion.
 *
 * Nothing on this table may ever hold AML flags, credit scores, balances or
 * counterparty identities — §14.2 treats a leak of any of them as a serious
 * compliance failure, and the schema is the first place to enforce it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reputation_stats', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->primary();

            $table->unsignedInteger('total_trades')->default(0);
            $table->unsignedBigInteger('total_volume_mg')->default(0);

            $table->unsignedInteger('settlements_total')->default(0);
            $table->unsignedInteger('settlements_on_time')->default(0);
            $table->unsignedInteger('settlements_late')->default(0);
            $table->unsignedInteger('settlements_defaulted')->default(0);
            $table->unsignedBigInteger('total_settlement_minutes')->default(0);

            $table->unsignedInteger('disputes_involved')->default(0);
            $table->unsignedInteger('disputes_lost')->default(0);
            $table->unsignedInteger('disputes_frivolous')->default(0);

            // Cannot be maintained by increment — recomputed nightly (§14.6).
            $table->unsignedInteger('distinct_counterparties')->default(0);

            $table->unsignedInteger('rfq_received')->default(0);
            $table->unsignedInteger('rfq_responded')->default(0);
            $table->unsignedBigInteger('rfq_total_response_minutes')->default(0);
            $table->unsignedInteger('quotes_accepted')->default(0);
            $table->unsignedInteger('quotes_filled')->default(0);

            $table->unsignedBigInteger('maker_volume_mg')->default(0);
            $table->unsignedBigInteger('taker_volume_mg')->default(0);

            $table->enum('verification_tier', VerificationTier::values())
                ->default(VerificationTier::BRONZE->value);
            $table->timestamp('tier_achieved_at')->nullable();
            $table->timestamp('promotion_locked_until')->nullable();

            $table->boolean('kyc_basic_verified')->default(false);
            $table->boolean('kyc_full_verified')->default(false);
            $table->boolean('bank_account_verified')->default(false);

            $table->timestamp('member_since')->useCurrent();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('verification_tier', 'idx_reputation_tier');
            $table->index('last_active_at', 'idx_reputation_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_stats');
    }
};
