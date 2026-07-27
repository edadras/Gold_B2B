<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/**
 * A new physical lot now exists. Ledger listens to post DEPOSIT_GOLD for
 * MEMBER_DEPOSIT / IMPORT origins; SPLIT / MERGE / MELT children are already
 * covered by the operation's own loss entry, so the listener keys off
 * originType. docs/02-architecture/03-events.md §Custody.
 */
final readonly class GoldLotCreated
{
    public function __construct(
        public int $lotId,
        public string $lotCode,
        public int $ownerOrganizationId,
        public int $grossWeightMg,
        public int $purityX10,
        public int $fineWeightMg,
        public string $puritySource,
        public string $originType,
        public string $status,
        public ?int $operationId,
        public ?int $createdByUserId,
        public string $occurredAt,
    ) {}
}
