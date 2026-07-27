<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Period roll-ups for trend display (docs/03-domain/14-reputation.md §14.5).
 *
 * The cumulative table can only ever answer "how good has this member been
 * overall", which flatters a member whose recent months are poor. The period
 * rows are what let the profile show a direction rather than a lifetime average.
 *
 * DAY rows are written incrementally as events arrive; WEEK and MONTH rows are
 * folded up from them by `reputation:recompute`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reputation_periods', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->enum('period_type', ['DAY', 'WEEK', 'MONTH']);
            $table->date('period_start');

            $table->unsignedInteger('trades')->default(0);
            $table->unsignedBigInteger('volume_mg')->default(0);
            $table->unsignedInteger('settlements_total')->default(0);
            $table->unsignedInteger('settlements_on_time')->default(0);
            // Null rather than zero when there were no settlements at all: "no
            // data" and "nothing was on time" are different facts.
            $table->unsignedInteger('on_time_rate_bps')->nullable();
            $table->unsignedInteger('disputes')->default(0);

            $table->unique(['organization_id', 'period_type', 'period_start'], 'uq_org_period');
            $table->index(['period_type', 'period_start'], 'idx_period_scan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_periods');
    }
};
