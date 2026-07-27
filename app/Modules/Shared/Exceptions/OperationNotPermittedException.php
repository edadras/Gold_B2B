<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

final class OperationNotPermittedException extends DomainException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('OPERATION_NOT_PERMITTED');
    }

    public function errorCode(): string
    {
        return 'OPERATION_NOT_PERMITTED';
    }

    public function userMessage(): string
    {
        return 'شما مجاز به انجام این عملیات نیستید.';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return [
            'reason' => $this->reason,
        ];
    }
}
