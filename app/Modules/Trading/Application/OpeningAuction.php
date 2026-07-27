<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Results\AuctionResult;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Illuminate\Support\Facades\DB;

/**
 * The batch auction of docs/03-domain/04-trading.md §4.8.
 *
 * PRE_OPEN accepts orders and matches none of them, so at 09:00 there is a book
 * that may already be crossed several levels deep. Sweeping it continuously
 * would print a different price at every level and hand the best of them to
 * whoever happened to be first in the queue. An auction instead asks one
 * question of the whole book — **at which single price does the most gold
 * change hands?** — and executes everything there. The same thing happens when
 * a circuit-breaker pause ends: the pause exists because price discovery
 * broke down, and resuming into a continuous sweep would let the first order
 * through set the price alone.
 *
 * Three properties make an auction defensible after the fact, and each is
 * implemented here rather than left to chance:
 *
 *  1. **One price.** Every fill in one auction prints the clearing price,
 *     whatever the individual orders' limits were. A buyer bidding 79,000,000
 *     against a clearing price of 78,500,000 pays 78,500,000 and gets the
 *     difference back off its reservation.
 *  2. **A deterministic price.** Maximum executable volume first, then the
 *     three tie-breaks documented on clearingPrice(). Given the same book, two
 *     runs — or the exchange and a member auditing it — must reach the same
 *     number.
 *  3. **A defensible queue.** Market orders first (they named no price and so
 *     accept any), then price priority, then time. The order that improved the
 *     book earliest is served first at the margin.
 *
 * Everything financial is delegated to MatchingEngine::executeCross(), which is
 * the same code continuous matching runs: self-trade refusal, fee terms, the
 * trade and fill rows, both reservations and the session's OHLCV. This class
 * only decides the price and the queue.
 *
 * Runs in one transaction, locking every candidate order in ascending id order
 * (AGENT_BRIEF rule 4), and dispatches nothing: MarketSessionService fires the
 * events after commit (rule 3).
 */
final class OpeningAuction
{
    /**
     * How much of the pre-open book one auction will consider.
     *
     * A cap is needed because the clearing-price search is O(prices × orders).
     * It is deliberately far above any plausible pre-open book so that in
     * practice the auction always sees all of it: a truncated book would
     * compute cumulative volumes from a subset and could clear at a price the
     * full book does not support.
     */
    private const MAX_ORDERS = 2_000;

    public function __construct(
        private readonly MatchingEngine $matcher,
        private readonly OrderStateMachine $stateMachine,
        private readonly PriceReaderInterface $prices,
    ) {}

    /**
     * Match the whole pre-open book of one instrument at a single price.
     *
     * Safe and cheap on an empty or one-sided book: it opens no transaction
     * and produces no trades, which is the common case for an instrument
     * nobody queued orders on overnight.
     */
    public function run(Instrument $instrument): AuctionResult
    {
        if (! $this->bookHasBothSides($instrument->id)) {
            return AuctionResult::empty();
        }

        /** @var AuctionResult $result */
        $result = DB::transaction(
            fn (): AuctionResult => $this->matchBatch($instrument),
            3, // the auction takes many row locks; a deadlock is worth retrying
        );

        return $result;
    }

    private function matchBatch(Instrument $instrument): AuctionResult
    {
        $book = $this->lockBook($instrument->id);

        $buys = array_values(array_filter($book, static fn (Order $o): bool => $o->side->isBuy()));
        $sells = array_values(array_filter($book, static fn (Order $o): bool => $o->side->isSell()));

        if ($buys === [] || $sells === []) {
            return AuctionResult::empty();
        }

        $clearing = $this->clearingPrice($instrument, $buys, $sells);

        if ($clearing === null) {
            return AuctionResult::empty();
        }

        return $this->allocate($instrument, $clearing, $buys, $sells);
    }

    // ── step 1: the clearing price ───────────────────────────────────────────

    /**
     * The price at which the greatest volume can execute.
     *
     * For every distinct limit price in the book — the only prices at which the
     * executable volume can change — compute
     *
     *     demand(p) = Σ remaining of buys willing to pay at least p
     *     supply(p) = Σ remaining of sells willing to accept at most p
     *     executable(p) = min(demand(p), supply(p))
     *
     * Market orders named no price, so they join both sums at every candidate.
     * The winner is the greatest executable volume; ties are broken in this
     * order, and the order matters because each rule is a different kind of
     * argument:
     *
     *   (a) **Smallest residual imbalance**, |demand − supply|. Two prices that
     *       trade the same volume are not equally good: the one that leaves
     *       less unfilled interest behind is the one the book is actually
     *       pointing at, and it is the standard first tie-break on every
     *       auction venue for that reason.
     *
     *   (b) **Closest to the reference price** from Pricing. When the book
     *       itself cannot choose, the outside world can. This is what stops a
     *       thin, deliberately balanced pair of orders from setting an opening
     *       price far away from where gold is actually trading.
     *
     *   (c) **The lower price.** Not an economic argument — a determinism one.
     *       Something has to decide, it must be reproducible from the book
     *       alone, and picking the lower price is the conservative choice for
     *       the side that must find the cash.
     *
     * Returns null when no candidate price crosses anything at all.
     *
     * @param  list<Order>  $buys
     * @param  list<Order>  $sells
     */
    private function clearingPrice(Instrument $instrument, array $buys, array $sells): ?PricePerFineGram
    {
        $candidates = $this->candidatePrices($buys, $sells);

        if ($candidates === []) {
            return null;
        }

        $reference = $this->referencePriceRial($instrument);

        $bestPrice = null;
        $bestVolume = 0;
        $bestImbalance = 0;
        $bestDistance = 0;

        foreach ($candidates as $price) {
            $demand = $this->cumulativeDemand($buys, $price);
            $supply = $this->cumulativeSupply($sells, $price);

            $volume = min($demand, $supply);

            if ($volume <= 0) {
                continue;
            }

            $imbalance = abs($demand - $supply);
            $distance = $reference === null ? 0 : abs($price - $reference);

            if ($bestPrice === null
                || $volume > $bestVolume
                || ($volume === $bestVolume && $imbalance < $bestImbalance)
                || ($volume === $bestVolume && $imbalance === $bestImbalance && $distance < $bestDistance)
            ) {
                // Tie-break (c) needs no branch: $candidates is ascending, so a
                // later price that is equal on all three measures never wins.
                $bestPrice = $price;
                $bestVolume = $volume;
                $bestImbalance = $imbalance;
                $bestDistance = $distance;
            }
        }

        return $bestPrice === null ? null : PricePerFineGram::fromRial($bestPrice);
    }

    /**
     * Every distinct limit price in the book, ascending.
     *
     * Executable volume is a step function of price that can only change where
     * an order's limit sits, so these are the only candidates worth testing.
     * A book of nothing but market orders has no candidate at all and cannot
     * be auctioned: there is no price in it to defend.
     *
     * @param  list<Order>  $buys
     * @param  list<Order>  $sells
     * @return list<int>
     */
    private function candidatePrices(array $buys, array $sells): array
    {
        $prices = [];

        foreach ([...$buys, ...$sells] as $order) {
            if ($order->price_rial !== null) {
                $prices[$order->price_rial] = true;
            }
        }

        $candidates = array_keys($prices);
        sort($candidates);

        /** @var list<int> $candidates */
        return $candidates;
    }

    /**
     * @param  list<Order>  $buys
     */
    private function cumulativeDemand(array $buys, int $price): int
    {
        $total = 0;

        foreach ($buys as $buy) {
            if ($buy->price_rial === null || $buy->price_rial >= $price) {
                $total += $buy->remainingMg();
            }
        }

        return $total;
    }

    /**
     * @param  list<Order>  $sells
     */
    private function cumulativeSupply(array $sells, int $price): int
    {
        $total = 0;

        foreach ($sells as $sell) {
            if ($sell->price_rial === null || $sell->price_rial <= $price) {
                $total += $sell->remainingMg();
            }
        }

        return $total;
    }

    private function referencePriceRial(Instrument $instrument): ?int
    {
        $price = $this->prices->referencePrice($instrument->id)
            ?? $this->prices->lastPrice($instrument->id);

        return $price?->rial;
    }

    // ── step 2: allocation at that single price ──────────────────────────────

    /**
     * Fill the crossing orders, all at the clearing price.
     *
     * Buys are served in queue order; each takes from the best available sell.
     * A pair that cannot legally trade — same organisation, or one has blocked
     * the other — is skipped and the buy moves to the next sell, so a blocked
     * counterparty costs that pair a fill and nobody else anything. Whatever is
     * left when the crossing side runs out simply stays in the book with its
     * remainder intact, which is how a marginal order survives the auction and
     * goes on to trade continuously.
     *
     * @param  list<Order>  $buys
     * @param  list<Order>  $sells
     */
    private function allocate(
        Instrument $instrument,
        PricePerFineGram $clearing,
        array $buys,
        array $sells,
    ): AuctionResult {
        $eligibleBuys = $this->queue(
            array_filter(
                $buys,
                static fn (Order $o): bool => $o->price_rial === null || $o->price_rial >= $clearing->rial,
            ),
            Side::BUY,
        );

        $eligibleSells = $this->queue(
            array_filter(
                $sells,
                static fn (Order $o): bool => $o->price_rial === null || $o->price_rial <= $clearing->rial,
            ),
            Side::SELL,
        );

        /** @var list<Trade> $trades */
        $trades = [];
        $volume = 0;
        /** @var array<int, true> $participants */
        $participants = [];

        foreach ($eligibleBuys as $buy) {
            while ($buy->remainingMg() > 0) {
                $sell = $this->firstTradableSell($eligibleSells, $buy);

                if ($sell === null) {
                    break;
                }

                $quantity = FineWeight::fromMilligrams(min($buy->remainingMg(), $sell->remainingMg()));

                // In a batch auction nobody took liquidity: every order was
                // resting when the auction was called. Fee terms still need a
                // maker, so it is the order that was entered first — the one
                // that had been advertising its price the longest.
                $maker = $this->earlier($buy, $sell);
                $taker = $maker->is($buy) ? $sell : $buy;

                $trade = $this->matcher->executeCross($taker, $maker, $quantity, $clearing, $instrument);

                // executeCross() reconciles the maker; the taker is ours.
                $this->stateMachine->reconcileFillStatus($taker, $trade->id);

                $trades[] = $trade;
                $volume += $quantity->milligrams;
                $participants[$buy->id] = true;
                $participants[$sell->id] = true;
            }
        }

        if ($trades === []) {
            return AuctionResult::empty();
        }

        return new AuctionResult(
            clearingPriceRial: $clearing->rial,
            matchedVolumeMg: $volume,
            orderCount: count($participants),
            trades: $trades,
        );
    }

    /**
     * Auction priority for one side: market orders, then price, then time.
     *
     * A market order accepted any price the auction produced, so it is served
     * before anyone who set a limit — the standard rule, and the one §4.8's
     * "تطبیق دسته‌ای" implies by putting market orders outside the price
     * ladder. Within the limits, the most aggressive price wins, and equal
     * prices are separated by when they were entered, exactly as in continuous
     * matching. The id is the final separator so the order is total.
     *
     * @param  array<int, Order>  $orders
     * @return list<Order>
     */
    private function queue(array $orders, Side $side): array
    {
        $queue = array_values($orders);

        usort($queue, static function (Order $a, Order $b) use ($side): int {
            $aMarket = $a->order_type === OrderType::MARKET || $a->price_rial === null;
            $bMarket = $b->order_type === OrderType::MARKET || $b->price_rial === null;

            if ($aMarket !== $bMarket) {
                return $aMarket ? -1 : 1;
            }

            if (! $aMarket) {
                $byPrice = $side->isBuy()
                    ? $b->price_rial <=> $a->price_rial   // buyers: highest bid first
                    : $a->price_rial <=> $b->price_rial;  // sellers: lowest offer first

                if ($byPrice !== 0) {
                    return $byPrice;
                }
            }

            $byTime = $a->placed_at <=> $b->placed_at;

            return $byTime !== 0 ? $byTime : $a->id <=> $b->id;
        });

        return $queue;
    }

    /**
     * The first sell in queue order this buy is allowed to trade with.
     *
     * @param  list<Order>  $sells
     */
    private function firstTradableSell(array $sells, Order $buy): ?Order
    {
        foreach ($sells as $sell) {
            if ($sell->remainingMg() <= 0) {
                continue;
            }

            if ($sell->organization_id === $buy->organization_id) {
                continue; // self-trade: excluded here, refused again by executeCross()
            }

            if ($this->matcher->isBlockedPair($buy->organization_id, $sell->organization_id)) {
                continue;
            }

            return $sell;
        }

        return null;
    }

    private function earlier(Order $a, Order $b): Order
    {
        $byTime = $a->placed_at <=> $b->placed_at;

        if ($byTime !== 0) {
            return $byTime < 0 ? $a : $b;
        }

        return $a->id <= $b->id ? $a : $b;
    }

    // ── loading and locking ──────────────────────────────────────────────────

    /** Cheap pre-check so the common empty open costs one query and no locks. */
    private function bookHasBothSides(int $instrumentId): bool
    {
        $sides = Order::query()
            ->where('instrument_id', $instrumentId)
            ->whereIn('status', OrderStatus::matchable())
            ->whereColumn('quantity_mg', '>', 'filled_mg')
            ->distinct()
            ->pluck('side')
            ->all();

        return count($sides) >= 2;
    }

    /**
     * The whole matchable book, locked FOR UPDATE in ascending id order.
     *
     * Ascending id and nothing else (AGENT_BRIEF rule 4): price-time order is
     * not a stable lock order, and an auction locking one way while a late
     * cancellation locks the other is a deadlock waiting for the first busy
     * open. Priority is restored in memory afterwards, in queue().
     *
     * @return list<Order>
     */
    private function lockBook(int $instrumentId): array
    {
        /** @var list<Order> $orders */
        $orders = Order::query()
            ->where('instrument_id', $instrumentId)
            ->whereIn('status', OrderStatus::matchable())
            ->whereColumn('quantity_mg', '>', 'filled_mg')
            ->orderBy('id')
            ->limit(self::MAX_ORDERS)
            ->lockForUpdate()
            ->get()
            ->all();

        return $orders;
    }
}
