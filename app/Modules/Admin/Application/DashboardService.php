<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\AmlAdminPort;
use App\Modules\Admin\Contracts\HealthIndicator;
use App\Modules\Admin\Contracts\LedgerAdminPort;
use App\Modules\Admin\Contracts\PlatformMetricsPort;
use App\Modules\Admin\Contracts\PlatformStats;
use App\Modules\Admin\Contracts\SettlementAdminPort;
use App\Modules\Admin\Contracts\WorkQueueCounts;

/**
 * Assembles the dashboard of docs/08-frontend-web/01-web-panels.md §1.9.
 *
 * The ordering in `criticalAlerts()` is the point of the screen. A ledger
 * discrepancy means the platform's books do not add up; nothing else on the
 * page matters until it is zero, so it is computed first, sorted first, and
 * given the loudest severity the view knows how to render.
 */
final class DashboardService
{
    public function __construct(
        private readonly PlatformMetricsPort $metrics,
        private readonly LedgerAdminPort $ledger,
        private readonly SettlementAdminPort $settlements,
        private readonly AmlAdminPort $aml,
    ) {}

    /**
     * @return array{
     *     alerts: list<array{key: string, severity: string, title: string, count: int, href: ?string}>,
     *     stats: PlatformStats,
     *     queues: WorkQueueCounts,
     *     settlementHealth: array{on_time: int, late: int, defaulted: int},
     *     health: list<HealthIndicator>,
     *     discrepancyCount: int
     * }
     */
    public function build(): array
    {
        $discrepancies = $this->ledger->discrepancyCount();
        $queues = $this->metrics->workQueues();

        return [
            'alerts' => $this->criticalAlerts($discrepancies, $queues->settlementsOverdue, $queues->settlementsDefaulted),
            'stats' => $this->metrics->stats(),
            'queues' => $queues,
            'settlementHealth' => $this->settlements->healthBreakdown(),
            'health' => $this->metrics->health(),
            'discrepancyCount' => $discrepancies,
        ];
    }

    /**
     * @return list<array{key: string, severity: string, title: string, count: int, href: ?string}>
     */
    private function criticalAlerts(int $discrepancies, int $overdue, int $defaulted): array
    {
        $criticalAml = $this->aml->countCritical();

        $alerts = [];

        // Always present, even at zero — «هیچ مغایرت دفتری وجود ندارد ✅» is
        // itself the reassurance the screen exists to give.
        $alerts[] = [
            'key' => 'ledger_discrepancy',
            'severity' => $discrepancies > 0 ? 'critical' : 'ok',
            'title' => $discrepancies > 0
                ? 'مغایرت دفتری'
                : 'هیچ مغایرت دفتری وجود ندارد',
            'count' => $discrepancies,
            'href' => 'admin.ledger.reconciliation',
        ];

        if ($defaulted > 0) {
            $alerts[] = [
                'key' => 'settlements_defaulted',
                'severity' => 'critical',
                'title' => 'تسویه نکول‌شده',
                'count' => $defaulted,
                'href' => 'admin.settlements.index',
            ];
        }

        if ($overdue > 0) {
            $alerts[] = [
                'key' => 'settlements_overdue',
                'severity' => 'warn',
                'title' => 'تسویه سررسیدگذشته',
                'count' => $overdue,
                'href' => 'admin.settlements.index',
            ];
        }

        if ($criticalAml > 0) {
            $alerts[] = [
                'key' => 'aml_critical',
                'severity' => 'warn',
                'title' => 'پرچم AML بحرانی',
                'count' => $criticalAml,
                'href' => 'admin.aml.index',
            ];
        }

        return $alerts;
    }
}
