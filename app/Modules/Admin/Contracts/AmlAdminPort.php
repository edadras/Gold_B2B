<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * AML is behind its own permission (docs §1.11: «دسترسی AML جدا از دسترسی
 * عمومی ادمین»), so it gets its own port rather than riding on a general one.
 */
interface AmlAdminPort
{
    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $severities
     * @return list<AmlFlagRow>
     */
    public function flags(array $statuses = [], array $severities = [], int $limit = 100): array;

    public function countOpen(): int;

    public function countCritical(): int;

    public function find(int $flagId): ?AmlFlagRow;

    public function investigationContext(int $flagId): ?AmlInvestigationContext;

    /** Move a flag to a new status with a mandatory analyst note. */
    public function recordDecision(
        int $flagId,
        int $analystUserId,
        string $status,
        string $notes,
        ?string $actionTaken,
    ): void;
}
