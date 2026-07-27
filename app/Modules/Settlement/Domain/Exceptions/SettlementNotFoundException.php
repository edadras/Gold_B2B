<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class SettlementNotFoundException extends DomainException
{
    public function __construct(public readonly string $entity, public readonly int $id)
    {
        parent::__construct(sprintf('%s %d not found', $entity, $id));
    }

    public function errorCode(): string
    {
        return 'SETTLEMENT_NOT_FOUND';
    }

    public function userMessage(): string
    {
        return 'تسویه موردنظر یافت نشد.';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function details(): array
    {
        return ['entity' => $this->entity, 'id' => $this->id];
    }
}
