<?php

declare(strict_types=1);

namespace App\Modules\Risk\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * A rule with action BLOCK matched. The member is told a compliance review is
 * in progress and nothing more — §12.10 tipping-off rule.
 */
final class AmlBlockedException extends DomainException
{
    /** @param list<string> $ruleCodes */
    public function __construct(public readonly array $ruleCodes)
    {
        parent::__construct('AML_BLOCKED');
    }

    public function errorCode(): string
    {
        return 'AML_BLOCKED';
    }

    public function userMessage(): string
    {
        return 'بررسی انطباق در جریان است.';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    /** Rule codes are intentionally omitted from the member-facing payload. */
    public function details(): array
    {
        return [];
    }
}
