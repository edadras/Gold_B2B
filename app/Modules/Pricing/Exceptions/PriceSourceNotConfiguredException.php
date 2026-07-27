<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** No enabled price source exists for the requested price type. */
final class PriceSourceNotConfiguredException extends DomainException
{
    public function __construct(public readonly string $priceType)
    {
        parent::__construct('PRICE_SOURCE_NOT_CONFIGURED');
    }

    public function errorCode(): string
    {
        return 'PRICE_SOURCE_NOT_CONFIGURED';
    }

    public function userMessage(): string
    {
        return 'منبع قیمتی برای این نوع قیمت تعریف نشده است.';
    }

    public function details(): array
    {
        return ['priceType' => $this->priceType];
    }
}
