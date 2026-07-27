<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

/**
 * What a channel driver reports back.
 *
 * `notificationId` is set only by the in-app driver, which is the one that
 * creates the `notifications` row every other channel then references.
 */
final readonly class DeliveryOutcome
{
    public const QUEUED = 'QUEUED';

    public const SENT = 'SENT';

    public const DELIVERED = 'DELIVERED';

    public const FAILED = 'FAILED';

    public const SKIPPED = 'SKIPPED';

    public function __construct(
        public string $status,
        public ?int $notificationId = null,
        public ?string $providerRef = null,
        public ?string $error = null,
    ) {}

    public static function sent(?string $providerRef = null, ?int $notificationId = null): self
    {
        return new self(self::SENT, $notificationId, $providerRef);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, null, null, $reason);
    }

    public static function failed(string $error): self
    {
        return new self(self::FAILED, null, null, $error);
    }

    public function succeeded(): bool
    {
        return $this->status === self::SENT || $this->status === self::DELIVERED;
    }
}
