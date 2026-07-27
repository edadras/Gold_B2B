<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** The request itself is malformed: empty split, oversized parts, no inputs. */
final class InvalidLotOperationException extends DomainException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $problem,
        /** @var array<string, mixed> */
        public readonly array $context = [],
    ) {
        parent::__construct("{$operation}: {$problem}");
    }

    public function errorCode(): string
    {
        return 'INVALID_LOT_OPERATION';
    }

    public function userMessage(): string
    {
        return 'درخواست عملیات روی قطعه معتبر نیست.';
    }

    public function details(): array
    {
        return ['operation' => $this->operation, 'problem' => $this->problem] + $this->context;
    }
}
