<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;

/**
 * Split a lot into N children.
 *
 * $physicalLossGrossMg is real swarf lost to the saw, not rounding. Rounding
 * loss is derived, never requested.
 */
final readonly class SplitLotCommand
{
    /** @param list<SplitPart> $parts */
    public function __construct(
        public int $parentLotId,
        public array $parts,
        public int $requestedByUserId,
        public int $physicalLossGrossMg = 0,
        public bool $remainderChild = true,
        public ?string $reason = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
    ) {
        if ($parts === []) {
            throw new InvalidLotOperationException('SPLIT', 'at least one child part is required');
        }

        if ($physicalLossGrossMg < 0) {
            throw new InvalidLotOperationException('SPLIT', 'physical loss cannot be negative');
        }
    }
}
