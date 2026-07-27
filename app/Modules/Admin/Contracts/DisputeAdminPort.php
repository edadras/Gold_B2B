<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

interface DisputeAdminPort
{
    /**
     * @param  list<string>  $statuses
     * @return list<DisputeRow>
     */
    public function queue(array $statuses = [], int $limit = 100): array;

    public function countAwaitingMediation(): int;

    public function find(int $disputeId): ?DisputeRow;

    public function investigationContext(int $disputeId): ?DisputeInvestigationContext;

    /** Assign a platform mediator; the note lands on the dispute timeline. */
    public function assignMediator(int $disputeId, int $mediatorUserId, int $actorUserId, string $note): void;
}
