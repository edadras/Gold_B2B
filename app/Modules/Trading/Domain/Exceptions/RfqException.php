<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** An RFQ or one of its quotes cannot do what was asked (§4.7). */
final class RfqException extends DomainException
{
    /** @param array<string, mixed> $context */
    private function __construct(
        private readonly string $code,
        private readonly string $persianMessage,
        private readonly array $context,
        string $developerMessage,
    ) {
        parent::__construct($developerMessage);
    }

    public static function expired(int $rfqId): self
    {
        return new self(
            'RFQ_EXPIRED',
            'مهلت این درخواست قیمت به پایان رسیده است.',
            ['rfq_id' => $rfqId],
            "RFQ {$rfqId} has expired",
        );
    }

    public static function quoteExpired(int $quoteId): self
    {
        return new self(
            'RFQ_QUOTE_EXPIRED',
            'اعتبار این پیشنهاد قیمت به پایان رسیده است.',
            ['quote_id' => $quoteId],
            "RFQ quote {$quoteId} is past its valid_until",
        );
    }

    public static function notInvited(int $rfqId, int $organizationId): self
    {
        return new self(
            'RFQ_NOT_INVITED',
            'شما به این درخواست قیمت دعوت نشده‌اید.',
            ['rfq_id' => $rfqId, 'organization_id' => $organizationId],
            "Organization {$organizationId} is not a recipient of RFQ {$rfqId}",
        );
    }

    public static function selfQuote(int $rfqId, int $organizationId): self
    {
        return new self(
            'RFQ_SELF_QUOTE',
            'نمی‌توانید به درخواست قیمت خودتان پاسخ دهید.',
            ['rfq_id' => $rfqId, 'organization_id' => $organizationId],
            "Organization {$organizationId} cannot quote its own RFQ {$rfqId}",
        );
    }

    public static function notOwner(int $rfqId, int $organizationId): self
    {
        return new self(
            'RFQ_NOT_OWNER',
            'فقط درخواست‌کننده می‌تواند این عملیات را انجام دهد.',
            ['rfq_id' => $rfqId, 'organization_id' => $organizationId],
            "Organization {$organizationId} does not own RFQ {$rfqId}",
        );
    }

    public static function exceedsRemaining(int $rfqId, int $requestedMg, int $remainingMg): self
    {
        return new self(
            'RFQ_EXCEEDS_REMAINING',
            'حجم پذیرفته‌شده از باقیمانده درخواست بیشتر است.',
            ['rfq_id' => $rfqId, 'requested_mg' => $requestedMg, 'remaining_mg' => $remainingMg],
            "Accepting {$requestedMg}mg exceeds the {$remainingMg}mg still open on RFQ {$rfqId}",
        );
    }

    public static function partialNotAllowed(int $rfqId): self
    {
        return new self(
            'RFQ_PARTIAL_NOT_ALLOWED',
            'این درخواست قیمت پذیرش جزئی را نمی‌پذیرد.',
            ['rfq_id' => $rfqId],
            "RFQ {$rfqId} was created with allow_partial = false",
        );
    }

    /**
     * Rule 2 of §4.7: the soft lock could not be hardened because the balance
     * was spent in the meantime. The quoter also earns a reputation penalty.
     */
    public static function softReservationLost(int $quoteId, int $quoterOrgId): self
    {
        return new self(
            'RFQ_SOFT_RESERVATION_LOST',
            'موجودی پیشنهاددهنده در این فاصله مصرف شده و پیشنهاد قابل اجرا نیست.',
            ['quote_id' => $quoteId, 'quoter_organization_id' => $quoterOrgId],
            "Quote {$quoteId}: quoter {$quoterOrgId} no longer has the balance it soft-reserved",
        );
    }

    public function errorCode(): string
    {
        return $this->code;
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
