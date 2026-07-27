<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Reporting\Console\BuildDailySummaryCommand;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Infrastructure\NullReportingDataSource;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

/**
 * Wiring for the reporting module.
 *
 * Reporting depends on Shared and Identity only, by design: it reads from every
 * other module but imports none of them, so a new report can never introduce a
 * dependency edge. Everything it needs arrives through ReportingDataSource, and
 * the modules that own those figures bind an implementation from their side.
 *
 * The default binding produces empty, internally consistent reports rather than
 * failing — a fresh install can run every report and see the reconciliation
 * machinery working on zeros.
 */
final class ReportingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            ReportingDataSource::class => NullReportingDataSource::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            BuildDailySummaryCommand::class,
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/reporting.php', 'goldb2b.reporting');

        parent::register();
    }
}
