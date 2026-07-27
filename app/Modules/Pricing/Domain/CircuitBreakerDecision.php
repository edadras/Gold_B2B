<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * F24 outcome. Pricing decides, Trading acts — this object never halts anything
 * by itself (docs/03-domain/07-pricing.md §7.5).
 */
final readonly class CircuitBreakerDecision
{
    public function __construct(
        public int $instrumentId,
        public int $referenceRial,
        public int $currentRial,
        public int $deviationBps,
        public int $thresholdBps,
        public bool $shouldHalt,
    ) {}

    public static function notEvaluated(int $instrumentId): self
    {
        return new self($instrumentId, 0, 0, 0, 0, false);
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'instrument_id' => $this->instrumentId,
            'reference_rial' => $this->referenceRial,
            'current_rial' => $this->currentRial,
            'deviation_bps' => $this->deviationBps,
            'threshold_bps' => $this->thresholdBps,
            'should_halt' => $this->shouldHalt,
        ];
    }
}
