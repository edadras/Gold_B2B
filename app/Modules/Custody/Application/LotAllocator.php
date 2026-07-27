<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Contracts\DTO\AllocationItem;
use App\Modules\Custody\Contracts\DTO\AllocationPlan;
use App\Modules\Custody\Contracts\LotAllocatorInterface;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Exceptions\InsufficientGoldLotsException;
use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chooses which lots deliver a requested fine weight —
 * docs/03-domain/02-gold-lot-assay.md §2.10.
 *
 * Strategy, in order:
 *   1. a single lot that matches the requirement exactly (zero splits)
 *   2. FIFO by acquisition date, taking whole lots while they still fit
 *   3. at every step, if some candidate exactly covers what is left, take it
 *      instead of splitting — the plan never needs more than one split
 *
 * Candidate rows are locked FOR UPDATE in ascending id order, so the caller
 * must already own a transaction (AGENT_BRIEF rules 4 and 5).
 *
 * Note on FIFO: gold_lots has no separate acquisition timestamp, so created_at
 * is the acquisition date. A lot changes owner without being recreated, so a
 * bought lot keeps the date it was minted into the system; that is the
 * conservative choice because it favours releasing older metal first.
 */
final class LotAllocator implements LotAllocatorInterface
{
    public function allocate(
        int $ownerOrganizationId,
        FineWeight $required,
        ?Purity $minPurity = null,
        ?CustodianType $custodianType = null,
    ): AllocationPlan {
        if ($required->isZero()) {
            throw new InvalidLotOperationException('ALLOCATE', 'required fine weight must be positive');
        }

        /** @var list<GoldLotModel> $candidates */
        $candidates = $this->candidateQuery($ownerOrganizationId, $minPurity, $custodianType)
            ->orderBy('id')            // ascending lock order
            ->lockForUpdate()
            ->get()
            ->all();

        $available = IntMath::sum(array_map(
            static fn (GoldLotModel $l): int => (int) $l->fine_weight_mg,
            $candidates,
        ));

        if ($available < $required->milligrams) {
            throw new InsufficientGoldLotsException(
                organizationId: $ownerOrganizationId,
                requiredFineMg: $required->milligrams,
                availableFineMg: $available,
                minPurityX10: $minPurity?->value,
            );
        }

        // Defence in depth: the query filters on owner and status, and this
        // re-checks it on the locked rows before anything is planned.
        foreach ($candidates as $lot) {
            if ((int) $lot->owner_organization_id !== $ownerOrganizationId || $lot->status !== LotStatus::AVAILABLE) {
                throw new InvalidLotOperationException('ALLOCATE', 'candidate set contains a lot that is not owned and available', [
                    'lot_id' => (int) $lot->id,
                ]);
            }
        }

        $fifo = $this->sortFifo($candidates);
        $items = $this->plan($fifo, $required->milligrams);

        return new AllocationPlan(
            ownerOrganizationId: $ownerOrganizationId,
            requiredFineMg: $required->milligrams,
            items: $items,
            allocatedFineMg: array_sum(array_map(static fn (AllocationItem $i): int => $i->useFineMg, $items)),
            minPurityX10: $minPurity?->value,
        );
    }

    public function availableFineWeight(
        int $ownerOrganizationId,
        ?Purity $minPurity = null,
        ?CustodianType $custodianType = null,
    ): FineWeight {
        $total = (int) $this->candidateQuery($ownerOrganizationId, $minPurity, $custodianType)
            ->sum('fine_weight_mg');

        return FineWeight::fromMilligrams($total);
    }

    private function candidateQuery(
        int $ownerOrganizationId,
        ?Purity $minPurity,
        ?CustodianType $custodianType,
    ): Builder {
        $query = GoldLotModel::query()
            ->where('owner_organization_id', $ownerOrganizationId)
            ->where('status', LotStatus::AVAILABLE->value)
            ->where('fine_weight_mg', '>', 0);

        if ($minPurity !== null) {
            $query->where('purity_x10', '>=', $minPurity->value);
        }

        if ($custodianType !== null) {
            $query->where('custodian_type', $custodianType->value);
        }

        return $query;
    }

    /**
     * @param  list<GoldLotModel>  $lots
     * @return list<GoldLotModel>
     */
    private function sortFifo(array $lots): array
    {
        usort($lots, static function (GoldLotModel $a, GoldLotModel $b): int {
            $left = $a->created_at?->getTimestamp() ?? 0;
            $right = $b->created_at?->getTimestamp() ?? 0;

            return $left <=> $right ?: ((int) $a->id <=> (int) $b->id);
        });

        return array_values($lots);
    }

    /**
     * @param  list<GoldLotModel>  $fifo
     * @return list<AllocationItem>
     */
    private function plan(array $fifo, int $requiredMg): array
    {
        // 1. exact single-lot match wins outright — no split at all.
        $exact = $this->firstExact($fifo, $requiredMg);

        if ($exact !== null) {
            return [$this->item($exact, $requiredMg, whole: true)];
        }

        $items = [];
        $remaining = $requiredMg;

        foreach ($fifo as $lot) {
            if ($remaining <= 0) {
                break;
            }

            // 3. if something in the remaining pool covers the rest exactly,
            //    prefer it over splitting the current lot.
            $exactRest = $this->firstExact($fifo, $remaining, $this->usedIds($items));

            if ($exactRest !== null) {
                $items[] = $this->item($exactRest, $remaining, whole: true);

                return $items;
            }

            if (in_array((int) $lot->id, $this->usedIds($items), true)) {
                continue;
            }

            $lotFine = (int) $lot->fine_weight_mg;

            if ($lotFine <= $remaining) {
                $items[] = $this->item($lot, $lotFine, whole: true);
                $remaining -= $lotFine;

                continue;
            }

            // 2b. the last lot is bigger than what is left: split it.
            $items[] = $this->item($lot, $remaining, whole: false);
            $remaining = 0;
        }

        return $items;
    }

    /**
     * @param  list<GoldLotModel>  $lots
     * @param  list<int>  $excludeIds
     */
    private function firstExact(array $lots, int $targetMg, array $excludeIds = []): ?GoldLotModel
    {
        foreach ($lots as $lot) {
            if (in_array((int) $lot->id, $excludeIds, true)) {
                continue;
            }

            if ((int) $lot->fine_weight_mg === $targetMg) {
                return $lot;
            }
        }

        return null;
    }

    /**
     * @param  list<AllocationItem>  $items
     * @return list<int>
     */
    private function usedIds(array $items): array
    {
        return array_map(static fn (AllocationItem $i): int => $i->lotId, $items);
    }

    private function item(GoldLotModel $lot, int $useFineMg, bool $whole): AllocationItem
    {
        return new AllocationItem(
            lotId: (int) $lot->id,
            lotCode: (string) $lot->lot_code,
            lotGrossMg: (int) $lot->gross_weight_mg,
            lotFineMg: (int) $lot->fine_weight_mg,
            purityX10: (int) $lot->purity_x10,
            useFineMg: $useFineMg,
            whole: $whole,
        );
    }
}
