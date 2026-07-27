<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class SettlementEventRow
{
    public function __construct(
        public int $id,
        public ?string $fromStatus,
        public string $toStatus,
        public string $actorType,
        public ?int $actorUserId,
        public ?string $reason,
        public ?string $transactionGroup,
        public string $occurredAt,
    ) {}
}
