<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * A FOK order could not be filled in its entirety.
 *
 * Thrown from inside the placement transaction on purpose: rolling back is what
 * makes "all or nothing" true at the database level, so the order row, its
 * reservation and any partial fills vanish together
 * (docs/06-backend-laravel/02-implementation-guide.md §2.5).
 */
final class FillOrKillNotFilledException extends DomainException
{
    public function __construct(
        public readonly int $requestedMg = 0,
        public readonly int $availableMg = 0,
    ) {
        parent::__construct('Fill-or-kill order could not be completely filled');
    }

    public function errorCode(): string
    {
        return 'FOK_NOT_FILLED';
    }

    public function userMessage(): string
    {
        return 'عمق بازار برای اجرای کامل این سفارش کافی نبود؛ سفارش لغو شد.';
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return [
            'requested_mg' => $this->requestedMg,
            'available_mg' => $this->availableMg,
        ];
    }
}
