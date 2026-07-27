<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Shared\Exceptions\DomainException;

/** The lot exists but its current status forbids the requested operation. */
final class LotNotAvailableException extends DomainException
{
    public function __construct(
        public readonly int $lotId,
        public readonly LotStatus $currentStatus,
        public readonly string $operation,
    ) {
        parent::__construct(sprintf(
            'Lot %d is %s and cannot be used for %s',
            $lotId,
            $currentStatus->value,
            $operation,
        ));
    }

    public function errorCode(): string
    {
        return 'LOT_NOT_AVAILABLE';
    }

    public function userMessage(): string
    {
        return 'وضعیت فعلی این قطعه اجازه انجام عملیات را نمی‌دهد.';
    }

    public function details(): array
    {
        return [
            'lot_id' => $this->lotId,
            'status' => $this->currentStatus->value,
            'operation' => $this->operation,
        ];
    }
}
