<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * Fat-finger verdict for a single order price. docs/03-domain/07-pricing.md §7.8.
 */
final readonly class PriceGuardDecision
{
    public function __construct(
        public PriceGuardStatus $status,
        public int $deviationBps,
        public int $referenceRial,
        public int $orderRial,
        public int $warnThresholdBps,
        public int $rejectThresholdBps,
    ) {}

    public function isAccepted(): bool
    {
        return $this->status === PriceGuardStatus::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status === PriceGuardStatus::REJECTED;
    }

    /** Audit rows carry the UNUSUAL_PRICE marker whenever this is true. */
    public function isUnusual(): bool
    {
        return $this->status !== PriceGuardStatus::ACCEPTED;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'deviation_bps' => $this->deviationBps,
            'reference_rial' => $this->referenceRial,
            'order_rial' => $this->orderRial,
            'warn_threshold_bps' => $this->warnThresholdBps,
            'reject_threshold_bps' => $this->rejectThresholdBps,
        ];
    }
}
