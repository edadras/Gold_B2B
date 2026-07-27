<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Application\DailySummaryBuilder;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * The nightly rollup of §15.9, and the alarm that goes with it.
 *
 * A day where any member's summary fails its own invariant exits non-zero, so
 * the scheduler treats it as a failed job rather than a quiet log line. That is
 * the intent of «اگر برقرار نبود ► is_reconciled = FALSE + هشدار»: the flag on
 * the row is for the report, the exit status is for the operator.
 */
final class BuildDailySummaryCommand extends Command
{
    protected $signature = 'report:build-daily
        {--date= : The Y-m-d date to build; defaults to yesterday}
        {--org= : Build a single organisation instead of all active ones}';

    protected $description = 'Build daily_org_summary and daily_platform_summary for a date';

    public function handle(DailySummaryBuilder $builder): int
    {
        $date = $this->resolveDate();

        $organization = $this->option('org');

        if ($organization !== null) {
            $row = $builder->buildFor((int) $organization, $date);

            $this->line(sprintf(
                'org %s on %s: closing %d mg, %s',
                $organization,
                $date,
                $row->gold_closing_mg,
                $row->is_reconciled ? 'reconciled' : 'NOT RECONCILED',
            ));

            return $row->is_reconciled ? self::SUCCESS : self::FAILURE;
        }

        $result = $builder->buildAll($date);

        $this->info(sprintf(
            'Built %d organisation summaries for %s.',
            $result['organizations'],
            $date,
        ));

        foreach ($result['failures'] as $failure) {
            $this->error('Failed: '.$failure);
        }

        if ($result['unreconciled'] > 0) {
            $this->error(sprintf(
                '%d summary row(s) failed the closing-balance invariant of §15.9.',
                $result['unreconciled'],
            ));

            return self::FAILURE;
        }

        return $result['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveDate(): string
    {
        $option = $this->option('date');

        if ($option !== null) {
            return (string) $option;
        }

        return (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    }
}
