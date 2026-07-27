<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Business-rule refusals raised by the member-facing KYC services.
 *
 * One class with named constructors rather than four near-identical exception
 * classes: every one of these is a 422 carrying a stable machine code and a
 * Persian sentence, exactly the shape docs/05-api/01-conventions.md §1.5
 * renders, and the only thing that varies is which code.
 */
final class KycOperationException extends DomainException
{
    /** @param array<string, mixed> $context */
    private function __construct(
        private readonly string $code,
        private readonly string $persianMessage,
        private readonly array $context = [],
    ) {
        parent::__construct($code);
    }

    /**
     * A VERIFIED document is evidence a compliance officer has already acted
     * on. Removing it would silently invalidate the decision that cited it, so
     * a re-upload (which SUPERSEDES) is the only way forward.
     */
    public static function documentAlreadyVerified(int $documentId): self
    {
        return new self(
            'DOCUMENT_NOT_DELETABLE',
            'سند تأییدشده قابل حذف نیست؛ برای جایگزینی، نسخه جدید را بارگذاری کنید.',
            ['document_id' => $documentId, 'reason' => 'document_verified'],
        );
    }

    public static function invalidIban(): self
    {
        return new self(
            'BANK_ACCOUNT_IBAN_INVALID',
            'شماره شبا معتبر نیست.',
            ['reason' => 'iban_checksum_failed'],
        );
    }

    /**
     * The blind index over the IBAN is unique platform-wide, so this also
     * fires when another member has registered the same account. The message
     * deliberately does not say which of the two it was.
     */
    public static function duplicateIban(): self
    {
        return new self(
            'BANK_ACCOUNT_DUPLICATE',
            'این شماره شبا قبلاً ثبت شده است.',
            ['reason' => 'iban_already_registered'],
        );
    }

    public static function duplicateLicense(string $licenseNo): self
    {
        return new self(
            'LICENSE_DUPLICATE',
            'این شماره جواز قبلاً برای سازمان شما ثبت شده است.',
            ['license_no' => $licenseNo, 'reason' => 'license_already_registered'],
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

    public function details(): array
    {
        return $this->context;
    }
}
