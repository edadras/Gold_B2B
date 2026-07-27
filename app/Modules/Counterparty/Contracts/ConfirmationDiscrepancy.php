<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

/**
 * What the two sides disagree about, and the raw material for finding out why
 * (docs/03-domain/10-counterparty.md §10.4).
 *
 * Most discrepancies are a movement one side booked in a different period, or
 * one side missing entirely — so the delta alone is useless without the
 * movements of the period next to it. Both sides' movement lists are returned
 * (the second negated into the first's convention) precisely so a human can
 * scan for the row that appears in one list and not the other.
 */
final readonly class ConfirmationDiscrepancy
{
    /**
     * @param  list<StatementLine>  $requesterMovements
     * @param  list<StatementLine>  $counterpartyMovements  negated into the requester's sign convention
     */
    public function __construct(
        public int $confirmationId,
        public int $organizationId,
        public int $counterpartyOrgId,
        public string $status,
        public string $periodStart,
        public string $asOf,
        public int $requesterGoldMg,
        public int $requesterRial,
        public ?int $responderGoldMg,
        public ?int $responderRial,
        public ?int $goldDeltaMg,
        public ?int $rialDelta,
        public array $requesterMovements,
        public array $counterpartyMovements,
    ) {}

    public function isResolved(): bool
    {
        return $this->goldDeltaMg === 0 && $this->rialDelta === 0;
    }

    public function awaitingResponse(): bool
    {
        return $this->responderGoldMg === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'confirmation_id' => $this->confirmationId,
            'organization_id' => $this->organizationId,
            'counterparty_org_id' => $this->counterpartyOrgId,
            'status' => $this->status,
            'period_start' => $this->periodStart,
            'as_of' => $this->asOf,
            'requester' => ['gold_mg' => $this->requesterGoldMg, 'rial' => $this->requesterRial],
            'responder' => ['gold_mg' => $this->responderGoldMg, 'rial' => $this->responderRial],
            'delta' => ['gold_mg' => $this->goldDeltaMg, 'rial' => $this->rialDelta],
            'requester_movements' => array_map(
                static fn (StatementLine $l): array => $l->toArray(),
                $this->requesterMovements,
            ),
            'counterparty_movements' => array_map(
                static fn (StatementLine $l): array => $l->toArray(),
                $this->counterpartyMovements,
            ),
        ];
    }
}
