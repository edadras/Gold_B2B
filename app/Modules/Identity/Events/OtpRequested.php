<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * A one-time code needs delivering.
 *
 * Identity generates the code but has no way to send it — Notification depends
 * on Identity, not the other way round — so the code travels out on an event
 * and whichever channel is configured picks it up.
 */
final readonly class OtpRequested
{
    public function __construct(
        public string $mobile,
        public string $purpose,
        public string $code,
        public int $expiresInSeconds,
        public string $occurredAt,
    ) {}
}
