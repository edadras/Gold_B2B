<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Contracts\CostBasisSnapshot;
use App\Modules\Accounting\Contracts\SaleCostResult;
use App\Modules\Accounting\Domain\Exceptions\InsufficientInventoryException;
use App\Modules\Accounting\Infrastructure\Models\InventoryCostBasisModel;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Weighted-average inventory costing — F14, F15 and F16 of
 * docs/11-appendix/01-formulas.md.
 *
 * The asymmetry between the two write paths is the whole point of the method
 * and is easy to get wrong:
 *
 *   recordPurchase() RECOMPUTES the average. New gold at a new price changes
 *                    what the pile is worth per gram.
 *   recordSale()     DOES NOT. Selling removes quantity and the matching slice
 *                    of cost at the *existing* average, leaving the average
 *                    untouched. Recomputing it on a sale would let a member
 *                    manufacture profit by selling to themselves.
 *
 * Rounding is FLOOR throughout (§1.10 of the formulas appendix): understating
 * the carried cost is the conservative direction.
 *
 * Every mutation takes the member's row FOR UPDATE first. Two concurrent
 * purchases that both read the old average and both write a new one would each
 * overwrite the other's cost, and the loss would be silent.
 */
final class CostBasisService
{
    private const MG_PER_GRAM = 1_000;

    public function snapshot(int $organizationId): CostBasisSnapshot
    {
        /** @var ?InventoryCostBasisModel $row */
        $row = InventoryCostBasisModel::query()->find($organizationId);

        if ($row === null) {
            return CostBasisSnapshot::empty($organizationId);
        }

        return new CostBasisSnapshot(
            organizationId: $organizationId,
            quantityMg: $row->quantity_mg,
            totalCostRial: $row->total_cost_rial,
            averageCostPerGram: $row->average_cost_per_gram,
        );
    }

    /**
     * F14 — a purchase folds into the weighted average.
     *
     *   new_qty  = old_qty + purchase_qty
     *   new_cost = old_total_cost + purchase_cost
     *   new_avg  = floor(new_cost × 1000 / new_qty)      ← rial per fine gram
     *
     * The document's worked example: 100,000 mg at an average of 75,000,000
     * (total 7,500,000,000) plus 200,000 mg costing 15,600,000,000 gives
     * 300,000 mg at 23,100,000,000, i.e. an average of 77,000,000.
     */
    public function recordPurchase(int $organizationId, int $quantityMg, int $costRial): CostBasisSnapshot
    {
        if ($quantityMg <= 0) {
            throw new InvalidArgumentException('A purchase must have a positive quantity');
        }

        if ($costRial < 0) {
            throw new InvalidArgumentException('A purchase cost cannot be negative');
        }

        return DB::transaction(function () use ($organizationId, $quantityMg, $costRial): CostBasisSnapshot {
            $row = $this->lockRow($organizationId);

            $newQuantity = IntMath::add($row->quantity_mg, $quantityMg);
            $newCost = IntMath::add($row->total_cost_rial, $costRial);

            $newAverage = IntMath::mulDivFloor($newCost, self::MG_PER_GRAM, $newQuantity);

            $row->update([
                'quantity_mg' => $newQuantity,
                'total_cost_rial' => $newCost,
                'average_cost_per_gram' => $newAverage,
                'lifetime_bought_mg' => IntMath::add($row->lifetime_bought_mg, $quantityMg),
                'last_purchase_at' => now(),
            ]);

            return new CostBasisSnapshot($organizationId, $newQuantity, $newCost, $newAverage);
        });
    }

    /**
     * F15 + F16 — a sale consumes inventory at the current average.
     *
     *   cogs             = floor(sold_qty_mg × avg_cost_per_gram / 1000)
     *   realized_profit  = sale_revenue − cogs − fees
     *
     * The document's worked example: selling 150,000 mg at an average of
     * 77,000,000 costs 11,550,000,000; sold for 12,000,000,000 that is a gross
     * profit of 450,000,000, and 438,000,000 net of a 12,000,000 fee.
     *
     * The average is carried forward unchanged, by construction: the remaining
     * cost is reduced by exactly the COGS taken out.
     */
    public function recordSale(
        int $organizationId,
        int $quantityMg,
        int $saleRevenueRial,
        int $feesRial = 0,
    ): SaleCostResult {
        if ($quantityMg <= 0) {
            throw new InvalidArgumentException('A sale must have a positive quantity');
        }

        if ($saleRevenueRial < 0 || $feesRial < 0) {
            throw new InvalidArgumentException('Sale revenue and fees cannot be negative');
        }

        return DB::transaction(function () use (
            $organizationId,
            $quantityMg,
            $saleRevenueRial,
            $feesRial,
        ): SaleCostResult {
            $row = $this->lockRow($organizationId);

            if ($row->quantity_mg < $quantityMg) {
                throw new InsufficientInventoryException($organizationId, $quantityMg, $row->quantity_mg);
            }

            $average = $row->average_cost_per_gram;

            $cogs = IntMath::mulDivFloor($quantityMg, $average, self::MG_PER_GRAM);

            // Selling the last milligram must leave no cost stranded behind, so
            // the final sale takes whatever remains rather than the formula's
            // floored figure — otherwise rounding dust would accumulate forever
            // in an inventory that holds nothing.
            $remainingQuantity = IntMath::sub($row->quantity_mg, $quantityMg);

            if ($remainingQuantity === 0) {
                $cogs = $row->total_cost_rial;
            } elseif ($cogs > $row->total_cost_rial) {
                $cogs = $row->total_cost_rial;
            }

            $remainingCost = IntMath::sub($row->total_cost_rial, $cogs);

            $grossProfit = IntMath::sub($saleRevenueRial, $cogs);
            $realizedProfit = IntMath::sub($grossProfit, $feesRial);

            $row->update([
                'quantity_mg' => $remainingQuantity,
                'total_cost_rial' => $remainingCost,
                // F14 is a purchase-side formula. A sale never revalues.
                'average_cost_per_gram' => $remainingQuantity === 0 ? 0 : $average,
                'lifetime_sold_mg' => IntMath::add($row->lifetime_sold_mg, $quantityMg),
                'lifetime_realized_profit' => IntMath::add($row->lifetime_realized_profit, $realizedProfit),
                'last_sale_at' => now(),
            ]);

            return new SaleCostResult(
                soldQuantityMg: $quantityMg,
                costOfGoodsSold: $cogs,
                saleRevenue: $saleRevenueRial,
                fees: $feesRial,
                grossProfit: $grossProfit,
                realizedProfit: $realizedProfit,
                averageCostPerGram: $average,
                remainingQuantityMg: $remainingQuantity,
                remainingCostRial: $remainingCost,
            );
        });
    }

    /**
     * A weight-only correction — a re-assay finding less fine gold than declared.
     *
     * Quantity changes, total cost does not (the money was already spent), so
     * the average rises. That is the economically correct answer: the same
     * money now buys fewer grams.
     */
    public function recordQuantityAdjustment(int $organizationId, int $deltaMg): CostBasisSnapshot
    {
        if ($deltaMg === 0) {
            return $this->snapshot($organizationId);
        }

        return DB::transaction(function () use ($organizationId, $deltaMg): CostBasisSnapshot {
            $row = $this->lockRow($organizationId);

            $newQuantity = IntMath::add($row->quantity_mg, $deltaMg);

            if ($newQuantity < 0) {
                throw new InsufficientInventoryException($organizationId, -$deltaMg, $row->quantity_mg);
            }

            $newAverage = $newQuantity === 0
                ? 0
                : IntMath::mulDivFloor($row->total_cost_rial, self::MG_PER_GRAM, $newQuantity);

            $row->update([
                'quantity_mg' => $newQuantity,
                'average_cost_per_gram' => $newAverage,
            ]);

            return new CostBasisSnapshot($organizationId, $newQuantity, $row->total_cost_rial, $newAverage);
        });
    }

    /** Row-level lock, creating the row on first use. */
    private function lockRow(int $organizationId): InventoryCostBasisModel
    {
        /** @var ?InventoryCostBasisModel $row */
        $row = InventoryCostBasisModel::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->first();

        if ($row !== null) {
            return $row;
        }

        InventoryCostBasisModel::query()->insertOrIgnore([
            'organization_id' => $organizationId,
            'quantity_mg' => 0,
            'total_cost_rial' => 0,
            'average_cost_per_gram' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var InventoryCostBasisModel $row */
        $row = InventoryCostBasisModel::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->firstOrFail();

        return $row;
    }
}
