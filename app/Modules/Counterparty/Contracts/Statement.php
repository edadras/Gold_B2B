<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

/**
 * A counterparty statement for a period: opening balance, movements, closing
 * balance, and the reconciliation check that docs/03-domain/15 §15.7 makes
 * mandatory for every flow report.
 *
 * `isReconciled` is the whole point of the object. A statement that cannot show
 * its closing figure agreeing with the independently stored relation balance is
 * a red flag, not a rounding curiosity, and the UI renders it as one.
 */
final readonly class Statement
{
    /**
     * @param  list<StatementLine>  $lines
     */
    public function __construct(
        public int $organizationId,
        public int $counterpartyOrgId,
        public string $from,
        public string $to,
        public int $openingGoldMg,
        public int $openingRial,
        public array $lines,
        public int $closingGoldMg,
        public int $closingRial,
        public int $storedGoldMg,
        public int $storedRial,
        public bool $hasMovementsAfterPeriod,
        public bool $isReconciled,
        public ?RelationSnapshot $relation = null,
    ) {}

    public function movementCount(): int
    {
        return count($this->lines);
    }

    public function goldTurnoverMg(): int
    {
        return array_sum(array_map(
            static fn (StatementLine $l): int => abs($l->goldDeltaMg),
            $this->lines,
        ));
    }

    /**
     * The gap between what this statement computes and what the relation row
     * holds. Zero whenever `isReconciled` is true.
     *
     * @return array{gold_mg: int, rial: int}
     */
    public function reconciliationGap(): array
    {
        if ($this->hasMovementsAfterPeriod) {
            // Comparing a mid-history closing figure against today's balance
            // would always "fail"; the meaningful comparison in that case is
            // made by StatementService over the full history.
            return ['gold_mg' => 0, 'rial' => 0];
        }

        return [
            'gold_mg' => $this->closingGoldMg - $this->storedGoldMg,
            'rial' => $this->closingRial - $this->storedRial,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'counterparty_org_id' => $this->counterpartyOrgId,
            'from' => $this->from,
            'to' => $this->to,
            'opening' => ['gold_mg' => $this->openingGoldMg, 'rial' => $this->openingRial],
            'lines' => array_map(static fn (StatementLine $l): array => $l->toArray(), $this->lines),
            'closing' => ['gold_mg' => $this->closingGoldMg, 'rial' => $this->closingRial],
            'stored' => ['gold_mg' => $this->storedGoldMg, 'rial' => $this->storedRial],
            'has_movements_after_period' => $this->hasMovementsAfterPeriod,
            'is_reconciled' => $this->isReconciled,
            'relation' => $this->relation?->toArray(),
        ];
    }
}
