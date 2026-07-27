<?php

declare(strict_types=1);

namespace App\Modules\Risk\Events;

/** A pre-trade ceiling was hit. Emitted after the rejection, never inside a transaction. */
final readonly class LimitExceeded
{
    public function __construct(
        public int $organizationId,
        public int $userId,
        public string $limitType,
        public int $requested,
        public int $limit,
    ) {}
}
