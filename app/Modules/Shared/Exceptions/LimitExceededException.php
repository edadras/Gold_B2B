<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

final class LimitExceededException extends DomainException
{
    public function __construct(
        public readonly string $limitType,
        public readonly int $requested,
        public readonly int $limit,
    ) {
        parent::__construct('LIMIT_EXCEEDED');
    }

    public function errorCode(): string
    {
        return 'LIMIT_EXCEEDED';
    }

    public function userMessage(): string
    {
        return 'سقف مجاز شما برای این عملیات نقض شد.';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'limitType' => $this->limitType,
            'requested' => $this->requested,
            'limit' => $this->limit,
        ];
    }
}
