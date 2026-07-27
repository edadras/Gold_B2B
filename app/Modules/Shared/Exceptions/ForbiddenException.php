<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

/**
 * Authorisation refusals, carrying the documented error code rather than one
 * generic 403 — the client distinguishes "your role does not allow this"
 * (FORBIDDEN_ROLE) from "your organisation is suspended" (ORG_SUSPENDED) and
 * shows a different screen for each (docs/05-api/01-conventions.md §1.7).
 */
final class ForbiddenException extends DomainException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        private readonly string $errorCode,
        private readonly string $persianMessage,
        private readonly int $status = 403,
        private readonly array $context = [],
    ) {
        parent::__construct($errorCode);
    }

    public static function role(string $permission): self
    {
        return new self(
            'FORBIDDEN_ROLE',
            'نقش شما این عملیات را مجاز نمی‌کند.',
            403,
            ['required_permission' => $permission],
        );
    }

    public static function tenant(): self
    {
        return new self('FORBIDDEN_TENANT', 'این منبع متعلق به سازمان شما نیست.', 403);
    }

    public static function dualControl(string $action): self
    {
        return new self(
            'FORBIDDEN_DUAL_CONTROL',
            'این عملیات به تأیید کاربر دوم نیاز دارد.',
            403,
            ['action' => $action],
        );
    }

    /**
     * Translate a PermissionChecker denial reason into the documented envelope.
     *
     * The reason strings are Identity's, but the mapping belongs on the HTTP
     * boundary, so it lives with the exception the boundary renders.
     */
    public static function fromDenialReason(string $reason): self
    {
        if (str_starts_with($reason, 'missing_permission:')) {
            return self::role(substr($reason, strlen('missing_permission:')));
        }

        if (str_starts_with($reason, 'organization_status:')) {
            $status = substr($reason, strlen('organization_status:'));

            return match ($status) {
                'SUSPENDED' => new self('ORG_SUSPENDED', 'حساب سازمان شما معلق است.', 423, ['status' => $status]),
                'RESTRICTED' => new self('ORG_RESTRICTED', 'حساب سازمان شما محدود است.', 423, ['status' => $status]),
                default => new self('ORG_NOT_ACTIVE', 'حساب سازمان شما فعال نیست.', 423, ['status' => $status]),
            };
        }

        if (str_starts_with($reason, 'user_status:') || $reason === 'user_not_active') {
            return new self('AUTH_ACCOUNT_LOCKED', 'حساب کاربری شما فعال نیست.', 423, ['reason' => $reason]);
        }

        return match ($reason) {
            'tenancy_mismatch', 'organization_missing' => self::tenant(),
            'not_platform_staff' => self::role('platform.*'),
            default => new self('FORBIDDEN_ROLE', 'شما مجاز به انجام این عملیات نیستید.', 403, ['reason' => $reason]),
        };
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function userMessage(): string
    {
        return $this->persianMessage;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->context;
    }
}
