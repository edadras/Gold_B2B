<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Inputs to a merge/melt disagree on something other than purity — owner,
 * custodian or metal type. docs/03-domain/02-gold-lot-assay.md §2.5 invariants
 * 4 and 5.
 */
final class IncompatibleLotsException extends DomainException
{
    /** @param list<int> $lotIds */
    public function __construct(
        public readonly array $lotIds,
        public readonly string $attribute,
        public readonly string $explanation,
    ) {
        parent::__construct("Lots are not compatible on {$attribute}: {$explanation}");
    }

    public function errorCode(): string
    {
        return 'INCOMPATIBLE_LOTS';
    }

    public function userMessage(): string
    {
        return 'قطعات انتخاب‌شده قابل ترکیب نیستند؛ مالک، نگهدارنده و نوع فلز باید یکسان باشد.';
    }

    public function details(): array
    {
        return [
            'lot_ids' => $this->lotIds,
            'attribute' => $this->attribute,
            'explanation' => $this->explanation,
        ];
    }
}
