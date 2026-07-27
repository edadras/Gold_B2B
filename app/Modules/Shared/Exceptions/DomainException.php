<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

/**
 * Base class for business-rule failures.
 *
 * These are expected outcomes, not bugs: they carry a stable machine code and a
 * user-facing Persian message, and the exception handler renders them directly
 * as an API error envelope (docs/05-api/01-conventions.md §1.5).
 */
abstract class DomainException extends RuntimeException
{
    /** Stable machine-readable code, e.g. INSUFFICIENT_GOLD. */
    abstract public function errorCode(): string;

    /** Persian message shown to the end user. */
    abstract public function userMessage(): string;

    public function httpStatus(): int
    {
        return 422;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return [];
    }
}
