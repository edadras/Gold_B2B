<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** An OTC action is not available to this party in this state (§4.6). */
final class OtcNegotiationException extends DomainException
{
    /** @param array<string, mixed> $context */
    private function __construct(
        private readonly string $errorCode,
        private readonly string $persianMessage,
        private readonly array $context,
        string $developerMessage,
    ) {
        parent::__construct($developerMessage);
    }

    public static function roundLimitReached(int $offerId, int $maxRounds): self
    {
        return new self(
            'OTC_ROUND_LIMIT',
            'حداکثر تعداد پیشنهاد متقابل به پایان رسیده است.',
            ['offer_id' => $offerId, 'max_rounds' => $maxRounds],
            "OTC offer {$offerId} exhausted its {$maxRounds} counter-offer rounds",
        );
    }

    public static function notAParty(int $offerId, int $organizationId): self
    {
        return new self(
            'OTC_NOT_A_PARTY',
            'شما طرف این پیشنهاد نیستید.',
            ['offer_id' => $offerId, 'organization_id' => $organizationId],
            "Organization {$organizationId} is not a party to OTC offer {$offerId}",
        );
    }

    public static function cannotAcceptOwnTerms(int $offerId, int $organizationId): self
    {
        return new self(
            'OTC_OWN_TERMS',
            'پیشنهاد روی میز از طرف خود شماست و قابل پذیرش نیست.',
            ['offer_id' => $offerId, 'organization_id' => $organizationId],
            "Organization {$organizationId} proposed the current terms of offer {$offerId}",
        );
    }

    public static function expired(int $offerId): self
    {
        return new self(
            'OTC_EXPIRED',
            'مهلت این پیشنهاد به پایان رسیده است.',
            ['offer_id' => $offerId],
            "OTC offer {$offerId} has expired",
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function userMessage(): string
    {
        return $this->persianMessage;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->context;
    }
}
