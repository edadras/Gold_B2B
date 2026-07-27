<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * The owner does not hold enough AVAILABLE fine weight to satisfy an
 * allocation. docs/03-domain/02-gold-lot-assay.md §2.10.
 */
final class InsufficientGoldLotsException extends DomainException
{
    public function __construct(
        public readonly int $organizationId,
        public readonly int $requiredFineMg,
        public readonly int $availableFineMg,
        public readonly ?int $minPurityX10 = null,
    ) {
        parent::__construct(sprintf(
            'Organization %d has %d mg fine available but %d mg is required',
            $organizationId,
            $availableFineMg,
            $requiredFineMg,
        ));
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_GOLD';
    }

    public function userMessage(): string
    {
        return 'قطعات طلای در دسترس شما برای این مقدار کافی نیست.';
    }

    public function details(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'required_fine_mg' => $this->requiredFineMg,
            'available_fine_mg' => $this->availableFineMg,
            'shortfall_fine_mg' => $this->requiredFineMg - $this->availableFineMg,
            'min_purity_x10' => $this->minPurityX10,
        ];
    }
}
