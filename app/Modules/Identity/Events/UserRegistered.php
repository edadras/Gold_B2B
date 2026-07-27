<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

final readonly class UserRegistered
{
    public function __construct(
        public int $userId,
        public int $organizationId,
        public string $mobile,
        public string $fullName,
        public ?int $invitedByUserId,
        public string $occurredAt,
    ) {}
}
