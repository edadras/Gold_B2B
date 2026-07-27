<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

/**
 * One row of the running statement of §10.3: the movement itself plus the pair
 * balance *after* it, so the reader can follow the arithmetic line by line
 * instead of trusting the closing figure.
 */
final readonly class StatementLine
{
    public function __construct(
        public int $movementId,
        public string $occurredAt,
        public string $kind,
        public ?string $reference,
        public ?string $description,
        public int $goldDeltaMg,
        public int $rialDelta,
        public int $runningGoldMg,
        public int $runningRial,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'movement_id' => $this->movementId,
            'occurred_at' => $this->occurredAt,
            'kind' => $this->kind,
            'reference' => $this->reference,
            'description' => $this->description,
            'gold_delta_mg' => $this->goldDeltaMg,
            'rial_delta' => $this->rialDelta,
            'running_gold_mg' => $this->runningGoldMg,
            'running_rial' => $this->runningRial,
        ];
    }
}
