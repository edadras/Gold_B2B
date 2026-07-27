<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\HealthIndicator;
use App\Modules\Admin\Contracts\PlatformMetricsPort;
use App\Modules\Admin\Contracts\PlatformStats;
use App\Modules\Admin\Contracts\WorkQueueCounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard's numbers (docs/08-frontend-web/01-web-panels.md §1.9).
 *
 * Aggregates across six modules' tables. Reporting owns `daily_platform_summary`
 * and would be the natural source once it publishes a read contract; until then
 * today's figures are computed live, because a dashboard that shows yesterday's
 * overdue settlements is worse than one that shows none.
 */
final class TablePlatformMetricsAdapter implements PlatformMetricsPort
{
    public function stats(): PlatformStats
    {
        $today = now()->startOfDay();
        $yesterday = (clone $today)->subDay();

        $todayTrades = $this->tradeAggregate($today->toDateTimeString(), null);
        $yesterdayTrades = $this->tradeAggregate(
            $yesterday->toDateTimeString(),
            $today->toDateTimeString(),
        );

        return new PlatformStats(
            activeMembers: $this->countOrganizations('ACTIVE'),
            newMembersToday: $this->newMembersSince($today->toDateTimeString()),
            volumeTodayFineMg: $todayTrades['fine_mg'],
            volumeYesterdayFineMg: $yesterdayTrades['fine_mg'],
            tradesToday: $todayTrades['count'],
            tradesYesterday: $yesterdayTrades['count'],
            feeIncomeTodayRial: $todayTrades['fees_rial'],
            feeIncomeYesterdayRial: $yesterdayTrades['fees_rial'],
        );
    }

    public function workQueues(): WorkQueueCounts
    {
        return new WorkQueueCounts(
            kycPending: $this->countIn('kyc_profiles', 'status', ['SUBMITTED', 'IN_REVIEW']),
            amlOpen: $this->countIn('aml_flags', 'status', TableAmlAdminAdapter::OPEN_STATUSES),
            amlCritical: $this->countCriticalAml(),
            disputesAwaitingMediation: $this->countIn(
                'disputes',
                'status',
                TableDisputeAdminAdapter::AWAITING_MEDIATION,
            ),
            custodyPendingApproval: $this->countIn('custody_operations', 'status', ['REQUESTED']),
            limitIncreaseRequests: $this->countIn('limit_increase_requests', 'status', ['PENDING_REVIEW']),
            settlementsOpen: $this->countIn(
                'settlements',
                'status',
                TableSettlementAdminAdapter::OPEN_STATUSES,
            ),
            settlementsOverdue: $this->countIn('settlements', 'status', ['OVERDUE']),
            settlementsDefaulted: $this->countIn('settlements', 'status', ['DEFAULTED']),
        );
    }

    /** @return list<HealthIndicator> */
    public function health(): array
    {
        $out = [];

        $out[] = new HealthIndicator(
            key: 'database',
            label: 'اتصال پایگاه داده',
            value: $this->databaseReachable() ? 'برقرار' : 'قطع',
            tone: $this->databaseReachable() ? 'ok' : 'bad',
        );

        $failedJobs = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        $out[] = new HealthIndicator(
            key: 'failed_jobs',
            label: 'failed_jobs',
            value: (string) $failedJobs,
            tone: $failedJobs === 0 ? 'ok' : 'bad',
        );

        $failedEvents = Schema::hasTable('failed_events')
            ? (int) DB::table('failed_events')->count()
            : 0;

        $out[] = new HealthIndicator(
            key: 'failed_events',
            label: 'رویدادهای ناموفق',
            value: (string) $failedEvents,
            tone: $failedEvents === 0 ? 'ok' : 'warn',
        );

        $lastSnapshot = Schema::hasTable('ledger_snapshots')
            ? DB::table('ledger_snapshots')->max('created_at')
            : null;

        $out[] = new HealthIndicator(
            key: 'last_snapshot',
            label: 'آخرین اسنپ‌شات دفتر',
            value: $lastSnapshot === null ? 'هرگز' : (string) $lastSnapshot,
            tone: $lastSnapshot === null ? 'warn' : 'ok',
        );

        $lastTick = Schema::hasTable('price_ticks')
            ? DB::table('price_ticks')->max('created_at')
            : null;

        $out[] = new HealthIndicator(
            key: 'price_feed',
            label: 'آخرین قیمت دریافتی',
            value: $lastTick === null ? 'بدون داده' : (string) $lastTick,
            tone: $lastTick === null ? 'warn' : 'ok',
        );

        $pendingNotifications = Schema::hasTable('notification_deliveries')
            ? (int) DB::table('notification_deliveries')->where('status', 'PENDING')->count()
            : 0;

        $out[] = new HealthIndicator(
            key: 'notification_queue',
            label: 'صف اعلان',
            value: (string) $pendingNotifications,
            tone: $pendingNotifications < 100 ? 'ok' : 'warn',
        );

        return $out;
    }

    /** @return array{count: int, fine_mg: int, fees_rial: int} */
    private function tradeAggregate(string $from, ?string $to): array
    {
        if (! Schema::hasTable('trades')) {
            return ['count' => 0, 'fine_mg' => 0, 'fees_rial' => 0];
        }

        $query = DB::table('trades')->where('executed_at', '>=', $from);

        if ($to !== null) {
            $query->where('executed_at', '<', $to);
        }

        $row = $query->selectRaw(
            'COUNT(*) AS total,
             COALESCE(SUM(quantity_fine_mg), 0) AS fine_mg,
             COALESCE(SUM(buyer_fee_rial), 0) + COALESCE(SUM(seller_fee_rial), 0) AS fees'
        )->first();

        return [
            'count' => (int) ($row->total ?? 0),
            'fine_mg' => (int) ($row->fine_mg ?? 0),
            'fees_rial' => (int) ($row->fees ?? 0),
        ];
    }

    private function countOrganizations(string $status): int
    {
        if (! Schema::hasTable('organizations')) {
            return 0;
        }

        return (int) DB::table('organizations')
            ->where('status', $status)
            ->where('is_platform', false)
            ->count();
    }

    private function newMembersSince(string $since): int
    {
        if (! Schema::hasTable('organizations')) {
            return 0;
        }

        return (int) DB::table('organizations')
            ->where('created_at', '>=', $since)
            ->where('is_platform', false)
            ->count();
    }

    /** @param list<string> $values */
    private function countIn(string $table, string $column, array $values): int
    {
        if ($values === [] || ! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $values)->count();
    }

    private function countCriticalAml(): int
    {
        if (! Schema::hasTable('aml_flags')) {
            return 0;
        }

        return (int) DB::table('aml_flags')
            ->where('severity', 'CRITICAL')
            ->whereIn('status', TableAmlAdminAdapter::OPEN_STATUSES)
            ->count();
    }

    private function databaseReachable(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
