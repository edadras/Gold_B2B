<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Resources;

use App\Modules\Counterparty\Contracts\Statement;
use App\Modules\Counterparty\Contracts\StatementLine;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /counterparties/{orgId}/statement` (docs §2.11).
 *
 * `is_reconciled` and `reconciliation_gap` are exposed rather than hidden: a
 * statement whose closing figure disagrees with the independently maintained
 * relation balance is a red flag the member is entitled to see, and
 * docs/03-domain/15 §15.7 makes the check mandatory for every flow report.
 *
 * @mixin Statement
 */
final class StatementResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Statement $statement */
        $statement = $this->resource;

        $gap = $statement->reconciliationGap();

        return [
            'organization_id' => $statement->organizationId,
            'counterparty_org_id' => $statement->counterpartyOrgId,
            'from' => $statement->from,
            'to' => $statement->to,
            'opening' => [
                'gold_mg' => $statement->openingGoldMg,
                'rial' => $statement->openingRial,
            ],
            'closing' => [
                'gold_mg' => $statement->closingGoldMg,
                'rial' => $statement->closingRial,
            ],
            'stored' => [
                'gold_mg' => $statement->storedGoldMg,
                'rial' => $statement->storedRial,
            ],
            'movement_count' => $statement->movementCount(),
            'gold_turnover_mg' => $statement->goldTurnoverMg(),
            'has_movements_after_period' => $statement->hasMovementsAfterPeriod,
            'is_reconciled' => $statement->isReconciled,
            'reconciliation_gap' => $gap,
            'lines' => array_map(
                fn (StatementLine $line): array => $this->line($request, $line),
                $statement->lines,
            ),
        ] + $this->display($request, [
            'opening_gold_display' => Display::grams($statement->openingGoldMg),
            'opening_rial_display' => Display::rial($statement->openingRial),
            'closing_gold_display' => Display::grams($statement->closingGoldMg),
            'closing_rial_display' => Display::rial($statement->closingRial),
            'gold_turnover_display' => Display::grams($statement->goldTurnoverMg()),
            'from_jalali' => Display::jalali($statement->from),
            'to_jalali' => Display::jalali($statement->to),
        ]);
    }

    /** @return array<string, mixed> */
    private function line(Request $request, StatementLine $line): array
    {
        return $line->toArray() + $this->display($request, [
            'gold_delta_display' => Display::grams($line->goldDeltaMg),
            'rial_delta_display' => Display::rial($line->rialDelta),
            'running_gold_display' => Display::grams($line->runningGoldMg),
            'running_rial_display' => Display::rial($line->runningRial),
            'occurred_at_jalali' => Display::jalali($line->occurredAt),
        ]);
    }
}
