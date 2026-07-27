<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * Ownership of the gold passed to the buyer (§5.2: GOLD_TRANSFERRING → SETTLED).
 *
 * physicalMovement is false for the CUSTODY_CHANGE pattern, where the metal
 * never leaves its box — see §5.3 pattern 4.
 *
 * @property list<int> $lotIds
 */
final readonly class GoldTransferred
{
    /** @param list<int> $lotIds */
    public function __construct(
        public int $settlementId,
        public int $goldTransferId,
        public int $fromOrganizationId,
        public int $toOrganizationId,
        public int $fineWeightMg,
        public string $deliveryMethod,
        public array $lotIds,
        public bool $splitPerformed,
        public bool $physicalMovement,
        public ?string $transactionGroup,
        public string $occurredAt,
    ) {}
}
