<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Application;

use App\Modules\Counterparty\Contracts\CreditHeadroom;
use App\Modules\Counterparty\Infrastructure\CounterpartyRelation;
use App\Modules\Shared\Exceptions\LimitExceededException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-counterparty credit limits (docs/03-domain/10-counterparty.md §10.5).
 *
 * The limit is one-directional and private: it is what *I* am willing to be
 * owed by *you*, and the counterparty is never shown the number. §10.5 also
 * defines an "effective limit" that is the minimum of this figure and the
 * platform-wide limits owned by the Risk module — that intersection is
 * deliberately not computed here, because Counterparty may not depend on Risk.
 * The caller that has both figures takes the min.
 */
final class CreditLimitService
{
    public function __construct(private readonly RelationService $relations) {}

    /**
     * Limits are set under a pessimistic lock rather than through the
     * accumulating upsert: unlike a balance delta, a limit is an absolute value,
     * so two concurrent edits must not interleave — the last writer has to see
     * what the previous one wrote.
     */
    public function setLimits(
        int $organizationId,
        int $counterpartyOrgId,
        ?int $goldLimitMg = null,
        ?int $rialLimit = null,
    ): CreditHeadroom {
        if ($goldLimitMg !== null && $goldLimitMg < 0) {
            throw new OperationNotPermittedException('A gold credit limit cannot be negative.');
        }

        if ($rialLimit !== null && $rialLimit < 0) {
            throw new OperationNotPermittedException('A rial credit limit cannot be negative.');
        }

        $this->relations->ensurePair($organizationId, $counterpartyOrgId);

        DB::transaction(function () use ($organizationId, $counterpartyOrgId, $goldLimitMg, $rialLimit): void {
            $relation = CounterpartyRelation::query()
                ->where('organization_id', $organizationId)
                ->where('counterparty_org_id', $counterpartyOrgId)
                ->lockForUpdate()
                ->firstOrFail();

            $changes = ['updated_at' => Carbon::now()->toDateTimeString()];

            if ($goldLimitMg !== null) {
                $changes['gold_credit_limit_mg'] = $goldLimitMg;
            }

            if ($rialLimit !== null) {
                $changes['rial_credit_limit'] = $rialLimit;
            }

            DB::table('counterparty_relations')
                ->where('id', $relation->id)
                ->update($changes);
        });

        return $this->remainingHeadroom($organizationId, $counterpartyOrgId);
    }

    /** @return array{gold_limit_mg: int, rial_limit: int} */
    public function limits(int $organizationId, int $counterpartyOrgId): array
    {
        $relation = $this->relations->relation($organizationId, $counterpartyOrgId);

        return [
            'gold_limit_mg' => $relation?->gold_credit_limit_mg ?? 0,
            'rial_limit' => $relation?->rial_credit_limit ?? 0,
        ];
    }

    public function remainingHeadroom(int $organizationId, int $counterpartyOrgId): CreditHeadroom
    {
        $relation = $this->relations->relation($organizationId, $counterpartyOrgId);

        $goldLimit = $relation?->gold_credit_limit_mg ?? 0;
        $rialLimit = $relation?->rial_credit_limit ?? 0;

        // Only a receivable consumes our limit; a payable is the other side's
        // exposure, measured against the other side's limit.
        $goldUsed = max(0, $relation?->gold_balance_mg ?? 0);
        $rialUsed = max(0, $relation?->rial_balance ?? 0);

        return new CreditHeadroom(
            organizationId: $organizationId,
            counterpartyOrgId: $counterpartyOrgId,
            goldLimitMg: $goldLimit,
            goldUsedMg: $goldUsed,
            goldRemainingMg: max(0, $goldLimit - $goldUsed),
            rialLimit: $rialLimit,
            rialUsed: $rialUsed,
            rialRemaining: max(0, $rialLimit - $rialUsed),
        );
    }

    /**
     * Would this trade push the counterparty past the limit we granted them?
     * Deltas use the same convention as RelationService::applyTrade.
     */
    public function wouldExceed(
        int $organizationId,
        int $counterpartyOrgId,
        int $goldDeltaMg,
        int $rialDelta,
    ): bool {
        $relation = $this->relations->relation($organizationId, $counterpartyOrgId);

        $projectedGold = ($relation?->gold_balance_mg ?? 0) + $goldDeltaMg;
        $projectedRial = ($relation?->rial_balance ?? 0) + $rialDelta;

        return $projectedGold > ($relation?->gold_credit_limit_mg ?? 0)
            || $projectedRial > ($relation?->rial_credit_limit ?? 0);
    }

    /**
     * §10.5: exceeding the limit is a warning that needs an explicit override,
     * not a silent block — so the caller decides between catching this and
     * asking the user, or letting it surface as a 422.
     *
     * @throws LimitExceededException
     */
    public function assertWithinLimits(
        int $organizationId,
        int $counterpartyOrgId,
        int $goldDeltaMg,
        int $rialDelta,
    ): void {
        $relation = $this->relations->relation($organizationId, $counterpartyOrgId);

        $projectedGold = ($relation?->gold_balance_mg ?? 0) + $goldDeltaMg;
        $goldLimit = $relation?->gold_credit_limit_mg ?? 0;

        if ($projectedGold > $goldLimit) {
            throw new LimitExceededException('counterparty_gold_credit', $projectedGold, $goldLimit);
        }

        $projectedRial = ($relation?->rial_balance ?? 0) + $rialDelta;
        $rialLimit = $relation?->rial_credit_limit ?? 0;

        if ($projectedRial > $rialLimit) {
            throw new LimitExceededException('counterparty_rial_credit', $projectedRial, $rialLimit);
        }
    }
}
