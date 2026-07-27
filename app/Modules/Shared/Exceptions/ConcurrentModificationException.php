<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

final class ConcurrentModificationException extends DomainException
{
    public function __construct(
        public readonly string $entity,
        public readonly int $id,
    ) {
        parent::__construct('CONCURRENT_MODIFICATION');
    }

    public function errorCode(): string
    {
        return 'CONCURRENT_MODIFICATION';
    }

    public function userMessage(): string
    {
        return 'این رکورد هم‌زمان توسط عملیات دیگری تغییر کرد. دوباره تلاش کنید.';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return [
            'entity' => $this->entity,
            'id' => $this->id,
        ];
    }
}
