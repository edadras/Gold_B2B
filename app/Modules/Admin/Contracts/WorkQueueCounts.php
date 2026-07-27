<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/** The «صف‌های کاری» block of §1.9. */
final readonly class WorkQueueCounts
{
    public function __construct(
        public int $kycPending,
        public int $amlOpen,
        public int $amlCritical,
        public int $disputesAwaitingMediation,
        public int $custodyPendingApproval,
        public int $limitIncreaseRequests,
        public int $settlementsOpen,
        public int $settlementsOverdue,
        public int $settlementsDefaulted,
    ) {}
}
