<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * The maker/checker rule was broken — most often a user trying to approve their
 * own request (docs/01-product/01-personas-roles.md §1.6).
 */
final class DualControlViolationException extends DomainException
{
    public function __construct(public readonly string $violation)
    {
        parent::__construct('DUAL_CONTROL_VIOLATION');
    }

    public static function selfApproval(): self
    {
        return new self('maker_cannot_be_checker');
    }

    public static function alreadyDecided(string $status): self
    {
        return new self('already_decided:'.$status);
    }

    public static function expired(): self
    {
        return new self('request_expired');
    }

    public function errorCode(): string
    {
        return 'DUAL_CONTROL_VIOLATION';
    }

    public function userMessage(): string
    {
        return 'این عملیات نیازمند تأیید کاربر دیگری است.';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['violation' => $this->violation];
    }
}
