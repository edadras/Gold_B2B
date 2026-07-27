<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** The one-time code presented at the vault counter did not verify. */
final class WaybillVerificationException extends DomainException
{
    public const REASON_BAD_CODE = 'BAD_CODE';

    public const REASON_EXPIRED = 'EXPIRED';

    public const REASON_ALREADY_USED = 'ALREADY_USED';

    public const REASON_CANCELLED = 'CANCELLED';

    public function __construct(
        public readonly string $waybillNo,
        public readonly string $reason,
    ) {
        parent::__construct("Waybill {$waybillNo} verification failed: {$reason}");
    }

    public function errorCode(): string
    {
        return 'WAYBILL_VERIFICATION_FAILED';
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::REASON_EXPIRED => 'اعتبار کد یکبارمصرف حواله به پایان رسیده است.',
            self::REASON_ALREADY_USED => 'این حواله قبلاً استفاده شده است.',
            self::REASON_CANCELLED => 'این حواله لغو شده است.',
            default => 'کد یکبارمصرف نادرست است.',
        };
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['waybill_no' => $this->waybillNo, 'reason' => $this->reason];
    }
}
