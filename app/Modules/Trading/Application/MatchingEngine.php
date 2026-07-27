<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Shared\Calculation\TradeValuation;
use App\Modules\Shared\Calculation\TradeValueCalculator;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\Exceptions\FillOrKillNotFilledException;
use App\Modules\Trading\Domain\Exceptions\SelfTradeException;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Domain\TimeInForce;
use App\Modules\Trading\Domain\TradeSource;
use App\Modules\Trading\Domain\TradeStatus;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OrderFill;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Price-time priority matching (docs/03-domain/04-trading.md §4.4).
 *
 * The three rules that matter, in order of how expensive they are to get wrong:
 *
 *  1. **The execution price is the MAKER's price.** The order that was already
 *     resting provided the liquidity and is rewarded for it, so a buyer who
 *     bid 78,500,000 against a resting ask of 78,480,000 pays 78,480,000. This
 *     is the standard for order-book markets and it is what worked example 1
 *     asserts.
 *  2. **Self-trade is impossible.** Excluded by the matching query, re-checked
 *     before any trade row is built, and refused a third time by the database's
 *     chk_no_self_trade. Wash trading distorts volume, is an AML red flag and
 *     has no economic meaning.
 *  3. **FOK is all-or-nothing.** When it cannot fill completely it throws and
 *     the caller's transaction rolls back, so the order row, the reservation
 *     and any partial fills disappear together. There is no compensating logic
 *     to get wrong.
 *
 * MUST be called from inside a transaction, and dispatches nothing: the caller
 * fires every event after commit (AGENT_BRIEF rule 3).
 */
final class MatchingEngine
{
    /** How deep one incoming order may sweep in a single pass. */
    private const MAX_CANDIDATES = 100;

    private ?bool $counterpartyTableExists = null;

    public function __construct(
        private readonly TradeValueCalculator $calculator,
        private readonly FeeSchedule $fees,
        private readonly ReservationCalculator $reservations,
        private readonly OrderStateMachine $stateMachine,
        private readonly CancelOrderService $canceller,
        private readonly SettlementDeadlineCalculator $deadlines,
        private readonly MarketSessionService $sessions,
        private readonly RialLedgerInterface $rialLedger,
    ) {}

    /**
     * Sweep the opposite side of the book with $incoming.
     *
     * @return list<Trade>
     *
     * @throws FillOrKillNotFilledException when a FOK order cannot be completed
     */
    public function match(Order $incoming, Instrument $instrument): array
    {
        /** @var list<Trade> $trades */
        $trades = [];

        foreach ($this->findMatchableOrders($incoming) as $maker) {
            $remaining = $incoming->remainingMg();

            if ($remaining <= 0) {
                break;
            }

            // Defence in depth: the query already excluded the incoming order's
            // own organisation, so reaching this branch means the query is
            // wrong, not the data. Log loudly and refuse the match.
            if ($maker->organization_id === $incoming->organization_id) {
                Log::warning('Self-trade candidate slipped through the matching query', [
                    'incoming_order_id' => $incoming->id,
                    'maker_order_id' => $maker->id,
                    'organization_id' => $incoming->organization_id,
                ]);

                continue;
            }

            $makerRemaining = $maker->remainingMg();

            if ($makerRemaining <= 0) {
                continue;
            }

            $executionPrice = PricePerFineGram::fromRial((int) $maker->price_rial);

            if ($this->exceedsSlippage($incoming, $executionPrice)) {
                break;
            }

            $quantity = FineWeight::fromMilligrams(min($remaining, $makerRemaining));

            $trades[] = $this->execute($incoming, $maker, $quantity, $executionPrice, $instrument);
        }

        $remaining = $incoming->remainingMg();
        $lastTrade = $trades === [] ? null : $trades[count($trades) - 1];

        $this->stateMachine->reconcileFillStatus($incoming, $lastTrade?->id);

        if ($incoming->time_in_force === TimeInForce::FOK && $remaining > 0) {
            // Rolls the caller's transaction back — see the class docblock.
            throw new FillOrKillNotFilledException(
                requestedMg: $incoming->quantity_mg,
                availableMg: $incoming->filled_mg,
            );
        }

        if ($incoming->time_in_force === TimeInForce::IOC && $remaining > 0) {
            $this->canceller->cancelWithinTransaction($incoming, 'IOC remainder cancelled');
        }

        return $trades;
    }

    /**
     * Candidate makers, in price-time priority, locked FOR UPDATE.
     *
     * Locking happens in a second pass ordered by id, because AGENT_BRIEF rule
     * 4 requires a deterministic lock order and price-time order is not one:
     * two orders sweeping the same level from opposite ends would deadlock.
     * Reading the candidate ids first, locking them in ascending id, then
     * restoring price-time order in memory gives both properties.
     *
     * No SKIP LOCKED: §2.5 of the implementation guide rules it out here,
     * because skipping a locked order would silently execute at a worse price
     * than the book's best. Blocking plus a short innodb_lock_wait_timeout and
     * a retry is the sanctioned trade-off.
     *
     * @return list<Order>
     */
    private function findMatchableOrders(Order $incoming): array
    {
        $query = Order::query()
            ->where('instrument_id', $incoming->instrument_id)
            ->where('side', $incoming->side->opposite()->value)
            ->whereIn('status', OrderStatus::matchable())
            ->where('organization_id', '!=', $incoming->organization_id)
            ->where('id', '!=', $incoming->id)
            ->whereColumn('quantity_mg', '>', 'filled_mg')
            ->whereNotNull('price_rial');

        $this->applyBlockedCounterpartyFilter($query, $incoming->organization_id);

        if ($incoming->order_type === OrderType::LIMIT) {
            $incoming->side->isBuy()
                ? $query->where('price_rial', '<=', $incoming->price_rial)
                : $query->where('price_rial', '>=', $incoming->price_rial);
        }

        /** @var list<int> $ids */
        $ids = $query
            ->orderBy('price_rial', $incoming->side->makerPriceDirection())
            ->orderBy('placed_at')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        sort($ids);

        /** @var list<Order> $locked */
        $locked = Order::query()
            ->whereIn('id', $ids)
            ->whereIn('status', OrderStatus::matchable())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();

        // Restore price-time priority now that every row is safely locked.
        usort($locked, static function (Order $a, Order $b) use ($incoming): int {
            $byPrice = $incoming->side->isBuy()
                ? $a->price_rial <=> $b->price_rial
                : $b->price_rial <=> $a->price_rial;

            if ($byPrice !== 0) {
                return $byPrice;
            }

            $byTime = $a->placed_at <=> $b->placed_at;

            return $byTime !== 0 ? $byTime : $a->id <=> $b->id;
        });

        return $locked;
    }

    /**
     * A member may block a counterparty; blocked pairs never match, in either
     * direction — neither side should be forced into a trade it has refused.
     *
     * counterparty_relations belongs to the Counterparty module, so it is
     * reached by raw table name and never by importing that module's model,
     * which the dependency graph forbids. The existence check keeps Trading
     * runnable in a deployment slice without the Counterparty module.
     *
     * @param  Builder<Order>  $query
     */
    private function applyBlockedCounterpartyFilter(Builder $query, int $organizationId): void
    {
        $this->counterpartyTableExists ??= Schema::hasTable('counterparty_relations');

        if ($this->counterpartyTableExists !== true) {
            return;
        }

        $query->whereNotExists(static function ($sub) use ($organizationId): void {
            $sub->select(DB::raw(1))
                ->from('counterparty_relations as cr')
                ->whereColumn('cr.organization_id', 'orders.organization_id')
                ->where('cr.counterparty_org_id', $organizationId)
                ->where('cr.is_blocked', true);
        });

        $query->whereNotExists(static function ($sub) use ($organizationId): void {
            $sub->select(DB::raw(1))
                ->from('counterparty_relations as cr2')
                ->where('cr2.organization_id', $organizationId)
                ->whereColumn('cr2.counterparty_org_id', 'orders.organization_id')
                ->where('cr2.is_blocked', true);
        });
    }

    /**
     * A MARKET order carries no limit price, so its protection is the slippage
     * band that sized its reservation (F10). Past it, stop sweeping and let the
     * remainder be cancelled rather than fill at a price the member never
     * agreed to.
     */
    private function exceedsSlippage(Order $incoming, PricePerFineGram $executionPrice): bool
    {
        if ($incoming->order_type !== OrderType::MARKET) {
            return false;
        }

        $worst = $incoming->metadata['worst_price_rial'] ?? null;

        if (! is_int($worst)) {
            return false;
        }

        return $incoming->side->isBuy()
            ? $executionPrice->rial > $worst
            : $executionPrice->rial < $worst;
    }

    /** Write the trade and its two fills, then settle both reservations. */
    private function execute(
        Order $taker,
        Order $maker,
        FineWeight $quantity,
        PricePerFineGram $price,
        Instrument $instrument,
    ): Trade {
        $buyer = $taker->side->isBuy() ? $taker : $maker;
        $seller = $taker->side->isSell() ? $taker : $maker;

        // Third net, under the query filter and the re-check in match().
        if ($buyer->organization_id === $seller->organization_id) {
            throw new SelfTradeException($buyer->organization_id);
        }

        $valuation = $this->calculator->value(
            fineWeight: $quantity,
            price: $price,
            buyerFee: $this->fees->termsFor($buyer->is($maker)),
            sellerFee: $this->fees->termsFor($seller->is($maker)),
            tax: $this->fees->taxTerms(),
        );

        $settlementType = $instrument->settlement_type;
        $executedAt = now();

        $trade = new Trade([
            'trade_code' => 'TRD-PENDING',
            'instrument_id' => $instrument->id,
            'trade_source' => TradeSource::ORDER_BOOK,
            'buy_order_id' => $buyer->id,
            'sell_order_id' => $seller->id,
            'buyer_organization_id' => $buyer->organization_id,
            'seller_organization_id' => $seller->organization_id,
            'maker_side' => $maker->side,
            'quantity_fine_mg' => $quantity->milligrams,
            'price_per_gram_rial' => $price->rial,
            'gross_amount_rial' => $valuation->grossAmount->amount,
            'buyer_fee_rial' => $valuation->buyerFee->amount,
            'seller_fee_rial' => $valuation->sellerFee->amount,
            'tax_rial' => $valuation->totalTax()->amount,
            'buyer_net_rial' => $valuation->buyerNet->amount,
            'seller_net_rial' => $valuation->sellerNet->amount,
            'settlement_type' => $settlementType,
            'delivery_type' => DeliveryType::CUSTODY_CHANGE,
            'settlement_deadline' => $this->deadlines->deadlineFor($settlementType),
            'status' => TradeStatus::EXECUTED,
            'executed_at' => $executedAt,
        ]);

        $trade->save();

        // The code is derived from the id, so it can only be written once the
        // row exists. Everything financial was final on the first write.
        $trade->trade_code = CodeGenerator::trade($trade->id);
        $trade->save();

        // Both sides advance before the reservations are settled, so
        // "does this fill complete the order?" is a question about state that
        // already includes this fill.
        $maker->filled_mg += $quantity->milligrams;
        $maker->save();

        $taker->filled_mg += $quantity->milligrams;
        $taker->save();

        $this->recordFill($buyer, $maker, $trade, $valuation);
        $this->recordFill($seller, $maker, $trade, $valuation);

        $this->settleBuyerReservation($buyer, $quantity, $price);
        $this->settleSellerReservation($seller, $quantity);

        $this->stateMachine->reconcileFillStatus($maker, $trade->id);

        $this->sessions->recordTrade($instrument->id, $price->rial, $quantity->milligrams);

        return $trade;
    }

    private function recordFill(Order $order, Order $maker, Trade $trade, TradeValuation $valuation): void
    {
        $isMaker = $order->is($maker);

        OrderFill::create([
            'order_id' => $order->id,
            'trade_id' => $trade->id,
            'instrument_id' => $trade->instrument_id,
            'organization_id' => $order->organization_id,
            'role' => $isMaker ? OrderFill::ROLE_MAKER : OrderFill::ROLE_TAKER,
            'side' => $order->side,
            'quantity_mg' => $trade->quantity_fine_mg,
            'price_rial' => $trade->price_per_gram_rial,
            'gross_amount_rial' => $valuation->grossAmount->amount,
            'fee_rial' => $order->side->isBuy()
                ? $valuation->buyerFee->amount
                : $valuation->sellerFee->amount,
            'filled_at' => $trade->executed_at,
        ]);
    }

    /**
     * Claim this fill's share of the buyer's rial reservation and hand the
     * surplus back — worked example 1's transaction group g3.
     *
     * The buyer reserved at its OWN price (F10). Executing at the maker's
     * better price means part of the lock is no longer needed, and holding on
     * to it would quietly shrink the member's buying power for no reason.
     */
    private function settleBuyerReservation(Order $buyer, FineWeight $quantity, PricePerFineGram $executionPrice): void
    {
        $outstanding = $buyer->outstandingReservation();

        if ($outstanding <= 0) {
            return;
        }

        $claim = min(
            $this->reservations->buyerClaimForFill($quantity, $executionPrice)->amount,
            $outstanding,
        );

        // Once the order is complete, everything still locked belongs to this
        // fill: whatever the claim does not need is surplus. While the order is
        // still partially open, only this fill's share at the order's own price
        // is up for release.
        $allocated = $buyer->remainingMg() <= 0
            ? $outstanding
            : min(
                $this->reservations
                    ->buyerRequirement($quantity, $buyer->price() ?? $executionPrice)
                    ->amount,
                $outstanding,
            );

        $surplus = max(0, $allocated - $claim);

        $buyer->consumed_amount += $claim;

        if ($surplus > 0 && $buyer->reservation_entry_id !== null) {
            $this->rialLedger->release(
                LedgerEntryId::fromInt($buyer->reservation_entry_id),
                Rial::fromRial($surplus),
            );

            $buyer->released_amount += $surplus;
        }

        $buyer->save();
    }

    /** A seller's lock is the metal itself; a fill simply claims part of it. */
    private function settleSellerReservation(Order $seller, FineWeight $quantity): void
    {
        $outstanding = $seller->outstandingReservation();

        if ($outstanding <= 0) {
            return;
        }

        $seller->consumed_amount += min($quantity->milligrams, $outstanding);
        $seller->save();
    }
}
