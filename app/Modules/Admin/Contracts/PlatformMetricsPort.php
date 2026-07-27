<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * Dashboard numbers, gathered from tables owned by Trading, Identity, Risk,
 * Settlement, Dispute and Custody.
 *
 * Admin may depend on Shared and Identity only, and none of those modules
 * publishes a platform-wide aggregate read, so this port exists and its default
 * adapter queries by table name. When a module grows a real reporting contract
 * (Reporting\Contracts\ReportingDataSource is the obvious home) rebind this and
 * delete the adapter.
 */
interface PlatformMetricsPort
{
    public function stats(): PlatformStats;

    public function workQueues(): WorkQueueCounts;

    /** @return list<HealthIndicator> */
    public function health(): array;
}
