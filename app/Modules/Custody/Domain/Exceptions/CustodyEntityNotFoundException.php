<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** A referenced lot, vault, box, laboratory or operation does not exist. */
final class CustodyEntityNotFoundException extends DomainException
{
    public function __construct(
        public readonly string $entity,
        public readonly int|string $identifier,
    ) {
        parent::__construct("{$entity} {$identifier} not found");
    }

    public function errorCode(): string
    {
        return 'CUSTODY_ENTITY_NOT_FOUND';
    }

    public function userMessage(): string
    {
        return 'رکورد مورد نظر یافت نشد.';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function details(): array
    {
        return ['entity' => $this->entity, 'id' => $this->identifier];
    }
}
