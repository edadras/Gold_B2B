<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

final class InvalidStateTransitionException extends DomainException
{
    public function __construct(
        public readonly string $entity,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct('INVALID_STATE_TRANSITION');
    }

    public function errorCode(): string
    {
        return 'INVALID_STATE_TRANSITION';
    }

    public function userMessage(): string
    {
        return 'وضعیت فعلی اجازه این عملیات را نمی‌دهد.';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'entity' => $this->entity,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
