<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** A referenced instrument, order, RFQ or offer does not exist. */
final class TradingEntityNotFoundException extends DomainException
{
    public function __construct(
        public readonly string $entity,
        public readonly string $identifier,
    ) {
        parent::__construct("{$entity} not found: {$identifier}");
    }

    public static function instrument(string $code): self
    {
        return new self('Instrument', $code);
    }

    public static function order(int $id): self
    {
        return new self('Order', (string) $id);
    }

    public static function rfq(int $id): self
    {
        return new self('Rfq', (string) $id);
    }

    public static function rfqQuote(int $id): self
    {
        return new self('RfqQuote', (string) $id);
    }

    public static function otcOffer(int $id): self
    {
        return new self('OtcOffer', (string) $id);
    }

    public static function marketSession(int $instrumentId): self
    {
        return new self('MarketSession', 'instrument:'.$instrumentId);
    }

    public function errorCode(): string
    {
        return 'NOT_FOUND';
    }

    public function userMessage(): string
    {
        return 'موردی با این مشخصات یافت نشد.';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return ['entity' => $this->entity, 'identifier' => $this->identifier];
    }
}
