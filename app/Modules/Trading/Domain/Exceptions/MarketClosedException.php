<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** No session is accepting orders for this instrument right now (§4.8). */
final class MarketClosedException extends DomainException
{
    public function __construct(
        public readonly string $instrumentCode,
        public readonly string $sessionStatus,
    ) {
        parent::__construct("Market is not open for {$instrumentCode} (session: {$sessionStatus})");
    }

    public function errorCode(): string
    {
        return 'MARKET_CLOSED';
    }

    public function userMessage(): string
    {
        return 'بازار در حال حاضر برای این ابزار باز نیست.';
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return [
            'instrument' => $this->instrumentCode,
            'session_status' => $this->sessionStatus,
        ];
    }
}
