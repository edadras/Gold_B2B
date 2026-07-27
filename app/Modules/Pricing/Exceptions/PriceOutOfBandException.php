<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** Order price deviated past hard rejection. docs/03-domain/07-pricing.md §7.8. */
final class PriceOutOfBandException extends DomainException
{
    public function __construct(
        public readonly int $deviationBps,
        public readonly int $thresholdBps,
        public readonly int $referenceRial,
        public readonly int $orderRial,
    ) {
        parent::__construct('PRICE_OUT_OF_BAND');
    }

    public function errorCode(): string
    {
        return 'PRICE_OUT_OF_BAND';
    }

    public function userMessage(): string
    {
        return 'قیمت سفارش بیش از حد مجاز با قیمت مرجع اختلاف دارد.';
    }

    public function details(): array
    {
        return [
            'deviationBps' => $this->deviationBps,
            'thresholdBps' => $this->thresholdBps,
            'referenceRial' => $this->referenceRial,
            'orderRial' => $this->orderRial,
        ];
    }
}
