<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * §9.7 — «بستن دوره: پس از بستن، سند جدید در آن دوره ثبت نمی‌شود».
 *
 * The fix is never to reopen the period: post a correcting voucher into the
 * current one instead (JournalPoster::postCorrection()).
 */
final class ClosedPeriodException extends DomainException
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $entryDate,
        public readonly string $periodCode,
    ) {
        parent::__construct(
            "Accounting period {$periodCode} of organisation {$organizationId} is closed; "
            ."cannot post on {$entryDate}"
        );
    }

    public function errorCode(): string
    {
        return 'ACCOUNTING_PERIOD_CLOSED';
    }

    public function userMessage(): string
    {
        return 'دوره مالی مربوط به این تاریخ بسته شده است؛ اصلاح باید در دوره جاری ثبت شود.';
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'entry_date' => $this->entryDate,
            'period_code' => $this->periodCode,
        ];
    }
}
