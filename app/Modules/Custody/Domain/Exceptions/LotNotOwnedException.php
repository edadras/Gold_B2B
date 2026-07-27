<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** Someone tried to act on metal that belongs to another organization. */
final class LotNotOwnedException extends DomainException
{
    public function __construct(
        public readonly int $lotId,
        public readonly int $actingOrganizationId,
    ) {
        parent::__construct(sprintf(
            'Lot %d does not belong to organization %d',
            $lotId,
            $actingOrganizationId,
        ));
    }

    public function errorCode(): string
    {
        return 'LOT_NOT_OWNED';
    }

    public function userMessage(): string
    {
        return 'این قطعه متعلق به سازمان شما نیست.';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        // Deliberately does not leak the real owner's id.
        return [
            'lot_id' => $this->lotId,
        ];
    }
}
