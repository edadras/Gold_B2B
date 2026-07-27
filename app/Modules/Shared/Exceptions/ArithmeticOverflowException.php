<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

/**
 * A financial computation produced a value outside the 64-bit integer range.
 *
 * This should never happen for realistic quantities; if it does, something
 * upstream is wrong and the operation must abort rather than silently wrap.
 */
final class ArithmeticOverflowException extends DomainException
{
    public function __construct(private readonly string $value)
    {
        parent::__construct("Arithmetic result {$value} exceeds 64-bit integer range");
    }

    public function errorCode(): string
    {
        return 'ARITHMETIC_OVERFLOW';
    }

    public function userMessage(): string
    {
        return 'مقدار محاسبه‌شده از محدوده مجاز سیستم فراتر رفت.';
    }

    public function httpStatus(): int
    {
        return 500;
    }

    public function details(): array
    {
        return ['value' => $this->value];
    }
}
