<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class DisputeInvestigationContext
{
    /**
     * @param  list<array{id: int, actor_type: string, actor_user_id: ?int, action: string, message: ?string, from_status: ?string, to_status: ?string, occurred_at: string}>  $timeline
     * @param  list<array{id: int, evidence_type: ?string, submitted_by_org_id: ?int, created_at: ?string}>  $evidence
     * @param  array<string, mixed>|null  $trade
     * @param  array<string, mixed>|null  $settlement
     */
    public function __construct(
        public DisputeRow $dispute,
        public array $timeline,
        public array $evidence,
        public ?array $trade,
        public ?array $settlement,
        public bool $holdReleased,
        public ?int $holdGoldEntryId,
        public ?int $holdRialEntryId,
    ) {}
}
