<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

use InvalidArgumentException;

/**
 * One directed, unsettled obligation feeding a netting run:
 * "settlement N says organisation A owes organisation B this much".
 *
 * Amount is a plain int because netting is asset-agnostic — milligrams for a
 * gold batch, rial for a cash batch. The batch's asset_type says which.
 */
final readonly class Obligation
{
    public function __construct(
        public int $settlementId,
        public int $fromOrganizationId,
        public int $toOrganizationId,
        public int $amount,
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Obligation amount must be positive, got: {$amount}");
        }

        if ($fromOrganizationId === $toOrganizationId) {
            throw new InvalidArgumentException('An organisation cannot owe itself');
        }
    }

    /** Canonical unordered pair key, so A↔B and B↔A land in the same bucket. */
    public function pairKey(): string
    {
        $low = min($this->fromOrganizationId, $this->toOrganizationId);
        $high = max($this->fromOrganizationId, $this->toOrganizationId);

        return $low.':'.$high;
    }

    /** True when this obligation runs in the pair's canonical (low → high) direction. */
    public function isForward(): bool
    {
        return $this->fromOrganizationId < $this->toOrganizationId;
    }
}
