<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Console;

use App\Modules\Settlement\Application\OverdueService;
use App\Modules\Settlement\Application\SettlementCompletionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `php artisan settlement:check-overdue` — the clock behind §5.5.
 *
 * Scheduled every config('goldb2b.settlement.overdue_check_minutes') minutes
 * (10 by default). Each run walks every open, past-deadline settlement one rung
 * further up the ladder: OVERDUE at T+0, penalty accrual from T+2h, operator
 * alert and order suspension at T+6h, DEFAULTED at T+24h.
 *
 * Exits non-zero when anything defaulted, so the scheduler surfaces it —
 * a default is a credit event, not routine housekeeping.
 */
final class CheckOverdueSettlementsCommand extends Command
{
    protected $signature = 'settlement:check-overdue
        {--limit=500 : Maximum settlements to examine in one run}
        {--at= : Evaluate as at this timestamp instead of now (testing and replay)}
        {--complete : Also close settlements whose 24h objection window has passed}';

    protected $description = 'Escalate overdue settlements through the §5.5 ladder and mark defaults';

    public function handle(OverdueService $overdue, SettlementCompletionService $completion): int
    {
        $now = $this->option('at') !== null
            ? CarbonImmutable::parse((string) $this->option('at'))
            : CarbonImmutable::now();

        $report = $overdue->sweep($now, (int) $this->option('limit'));

        $this->line(sprintf('Settlements examined: %d', $report['checked']));
        $this->line(sprintf('  newly overdue     : %d', $report['overdue']));
        $this->line(sprintf('  penalty accruing  : %d', $report['penalised']));
        $this->line(sprintf('  operator alerted  : %d', $report['escalated']));

        if ($report['defaulted'] > 0) {
            $this->error(sprintf('  ⛔ defaulted       : %d', $report['defaulted']));
        } else {
            $this->line('  defaulted         : 0');
        }

        if ($this->option('complete')) {
            $completed = $completion->completeDue($now);
            $this->line(sprintf('Objection window closed on %d settlement(s)', count($completed)));
        }

        return $report['defaulted'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
