<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * The dossier cannot be submitted yet. `missingItems` is machine-readable so
 * the UI can highlight exactly which upload or field is outstanding.
 */
final class IncompleteKycException extends DomainException
{
    /** @param  list<string>  $missingItems */
    public function __construct(public readonly array $missingItems)
    {
        parent::__construct('KYC_INCOMPLETE');
    }

    public function errorCode(): string
    {
        return 'KYC_INCOMPLETE';
    }

    public function userMessage(): string
    {
        return 'مدارک شما کامل نیست؛ موارد باقی‌مانده را تکمیل کنید.';
    }

    public function details(): array
    {
        return ['missing_items' => $this->missingItems];
    }
}
