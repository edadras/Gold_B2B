<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Commands\PlaceOrderCommand;
use App\Modules\Trading\Application\Results\OrderResult;
use App\Modules\Trading\Domain\Exceptions\InvalidOrderException;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Events\OrderFilled;
use App\Modules\Trading\Events\OrderPartiallyFilled;
use App\Modules\Trading\Events\OrderPlaced;
use App\Modules\Trading\Events\OrderRejected;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Illuminate\Support\Facades\DB;

/**
 * The canonical order placement flow: validate → transaction → events
 * (docs/06-backend-laravel/02-implementation-guide.md §2.2).
 *
 * Why the phases are separate and in this order:
 *
 *   **Validation runs outside the transaction.** A closed market, a violated
 *   limit or a bad tick size are refusals, not rollbacks. Doing them inside
 *   would hold locks while asking Risk questions that touch several tables.
 *
 *   **One short transaction does the money.** Reserve, insert the order, match.
 *   Nothing else. AGENT_BRIEF rule 8 budgets ~500ms and the matching sweep is
 *   the expensive part, so nothing avoidable shares its locks.
 *
 *   **Events fire after commit.** Rule 3: no queue, notification or broadcast
 *   inside a transaction. A listener that reads the database must see the same
 *   rows the caller does, and a rolled-back FOK must produce no events at all.
 */
final readonly class PlaceOrderService
{
    private const DEFAULT_MAX_SLIPPAGE_BPS = 50;

    public function __construct(
        private InstrumentRepository $instruments,
        private MarketSessionService $sessions,
        private RiskGuardInterface $risk,
        private GoldLedgerInterface $goldLedger,
        private RialLedgerInterface $rialLedger,
        private ReservationCalculator $reservations,
        private MatchingEngine $matcher,
        private OrderStateMachine $stateMachine,
        private OrderBookReader $book,
        private PriceReaderInterface $prices,
    ) {}

    public function place(PlaceOrderCommand $command): OrderResult
    {
        $instrument = $this->instruments->findByCodeOrFail($command->instrumentCode);

        // ── phase 1: validation, outside any transaction ──────────────────
        try {
            $this->sessions->assertOpen($instrument);
            $this->assertQuantityValid($command, $instrument);
            $this->assertPriceValid($command, $instrument);

            $referencePrice = $this->referencePriceFor($command, $instrument);

            $this->risk->assertTradeAllowed(
                $command->toIntent($referencePrice, $instrument->settlement_type->value)
            );
        } catch (DomainException $e) {
            event(new OrderRejected(
                orderId: null,
                organizationId: $command->organizationId,
                instrumentId: $instrument->id,
                side: $command->side->value,
                quantityMg: $command->quantity->milligrams,
                priceRial: $command->price?->rial,
                reasonCode: $e->errorCode(),
                reason: $e->getMessage(),
                occurredAt: now()->toIso8601String(),
            ));

            throw $e;
        }

        // ── phase 2: one atomic transaction ───────────────────────────────
        $shouldMatch = $this->sessions->isMatching($instrument->id);

        /** @var OrderResult $result */
        $result = DB::transaction(
            fn (): OrderResult => $this->reserveAndMatch($command, $instrument, $referencePrice, $shouldMatch),
            3, // retry on deadlock — the matching sweep takes many row locks
        );

        // ── phase 3: events, after commit ─────────────────────────────────
        $this->announce($command, $result);

        return $result;
    }

    private function reserveAndMatch(
        PlaceOrderCommand $command,
        Instrument $instrument,
        PricePerFineGram $referencePrice,
        bool $shouldMatch,
    ): OrderResult {
        $reservationPrice = $command->type === OrderType::MARKET
            ? $this->worstAcceptablePrice($command, $referencePrice)
            : ($command->price ?? $referencePrice);

        $reservedAmount = $this->reservations->amountFor($command->side, $command->quantity, $reservationPrice);

        $order = $this->createOrder($command, $instrument, $reservedAmount, $reservationPrice);

        // The reservation reference points at the order, which is why the row
        // has to exist first. InsufficientBalanceException from here rolls the
        // order back with it — worked example 5's losing thread.
        $reservationId = $command->side->isBuy()
            ? $this->rialLedger->reserve(
                $command->organizationId,
                $this->reservations->buyerRequirement($command->quantity, $reservationPrice),
                LedgerReference::order($order->id),
            )
            : $this->goldLedger->reserve(
                $command->organizationId,
                $this->reservations->sellerRequirement($command->quantity),
                LedgerReference::order($order->id),
            );

        $order->reservation_entry_id = $reservationId->value;
        $order->save();

        $this->stateMachine->open($order, $command->userId);

        $trades = $shouldMatch ? $this->matcher->match($order, $instrument) : [];

        return new OrderResult($order->refresh(), $trades);
    }

    private function createOrder(
        PlaceOrderCommand $command,
        Instrument $instrument,
        int $reservedAmount,
        PricePerFineGram $reservationPrice,
    ): Order {
        $metadata = $command->metadata;

        if ($command->type === OrderType::MARKET) {
            // The slippage band the reservation was sized against; the matching
            // engine refuses to cross it (F10).
            $metadata['worst_price_rial'] = $reservationPrice->rial;
        }

        $order = new Order([
            'order_code' => 'ORD-PENDING',
            'instrument_id' => $instrument->id,
            'organization_id' => $command->organizationId,
            'created_by_user_id' => $command->userId,
            'representative_id' => $command->representativeId,
            'side' => $command->side,
            'order_type' => $command->type,
            'time_in_force' => $command->timeInForce,
            'quantity_mg' => $command->quantity->milligrams,
            'filled_mg' => 0,
            'price_rial' => $command->price?->rial,
            'max_slippage_bps' => $command->maxSlippageBps,
            'reserved_amount' => $reservedAmount,
            'consumed_amount' => 0,
            'released_amount' => 0,
            'status' => OrderStatus::PENDING,
            'placed_at' => now(),
            'expires_at' => $command->expiresAt,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $order->save();

        $order->order_code = CodeGenerator::order($order->id);
        $order->save();

        return $order;
    }

    /** F10 — a MARKET buy reserves at best_ask × (1 + slippage), a sell at the mirror. */
    private function worstAcceptablePrice(PlaceOrderCommand $command, PricePerFineGram $reference): PricePerFineGram
    {
        $slippage = $command->maxSlippageBps ?? self::DEFAULT_MAX_SLIPPAGE_BPS;

        return $command->side->isBuy()
            ? $reference->worseForBuyer($slippage)
            : $reference->worseForSeller($slippage);
    }

    /**
     * The price a market order should be measured against: the far touch of our
     * own book first, then Pricing's last / reference price.
     *
     * A LIMIT order simply uses its own price.
     */
    private function referencePriceFor(PlaceOrderCommand $command, Instrument $instrument): PricePerFineGram
    {
        if ($command->price !== null) {
            return $command->price;
        }

        $touch = $command->side->isBuy()
            ? $this->book->bestAsk($instrument->id)
            : $this->book->bestBid($instrument->id);

        $price = $touch
            ?? $this->prices->lastPrice($instrument->id)
            ?? $this->prices->referencePrice($instrument->id);

        if ($price === null || $price->rial === 0) {
            throw InvalidOrderException::noMarketDepthForMarketOrder();
        }

        return $price;
    }

    private function assertQuantityValid(PlaceOrderCommand $command, Instrument $instrument): void
    {
        $mg = $command->quantity->milligrams;

        if ($mg < $instrument->min_order_mg) {
            throw InvalidOrderException::belowMinimum($mg, $instrument->min_order_mg);
        }

        if ($mg > $instrument->max_order_mg) {
            throw InvalidOrderException::aboveMaximum($mg, $instrument->max_order_mg);
        }

        if ($instrument->lot_size_mg > 0 && $mg % $instrument->lot_size_mg !== 0) {
            throw InvalidOrderException::notALotMultiple($mg, $instrument->lot_size_mg);
        }
    }

    private function assertPriceValid(PlaceOrderCommand $command, Instrument $instrument): void
    {
        if ($command->type !== OrderType::LIMIT) {
            return;
        }

        $price = $command->price ?? throw InvalidOrderException::missingPrice();

        if (! $instrument->acceptsPrice($price)) {
            throw InvalidOrderException::notATickMultiple($price->rial, $instrument->tick_size_rial);
        }
    }

    /** Everything that happens after commit, in the order a listener expects it. */
    private function announce(PlaceOrderCommand $command, OrderResult $result): void
    {
        $order = $result->order;

        event(new OrderPlaced(
            orderId: $order->id,
            orderCode: $order->order_code,
            instrumentId: $order->instrument_id,
            organizationId: $order->organization_id,
            userId: $command->userId,
            side: $order->side->value,
            orderType: $order->order_type->value,
            timeInForce: $order->time_in_force->value,
            quantityMg: $order->quantity_mg,
            priceRial: $order->price_rial,
            status: $order->status->value,
            occurredAt: $order->placed_at->toIso8601String(),
        ));

        foreach ($result->trades as $trade) {
            event(TradeEventFactory::executed($trade));
        }

        if ($order->status === OrderStatus::FILLED) {
            event(new OrderFilled(
                orderId: $order->id,
                organizationId: $order->organization_id,
                instrumentId: $order->instrument_id,
                side: $order->side->value,
                quantityMg: $order->quantity_mg,
                averagePriceRial: $this->averagePrice($result->trades),
                occurredAt: now()->toIso8601String(),
            ));
        } elseif ($order->status === OrderStatus::PARTIALLY_FILLED && $result->trades !== []) {
            $last = $result->trades[count($result->trades) - 1];

            event(new OrderPartiallyFilled(
                orderId: $order->id,
                organizationId: $order->organization_id,
                instrumentId: $order->instrument_id,
                side: $order->side->value,
                filledMg: $order->filled_mg,
                remainingMg: $order->remainingMg(),
                lastFillPriceRial: $last->price_per_gram_rial,
                occurredAt: now()->toIso8601String(),
            ));
        }
    }

    /**
     * Volume-weighted, using integer division: a display figure, never a
     * settlement input, so truncation is harmless and a float would not be.
     *
     * @param  list<Trade>  $trades
     */
    private function averagePrice(array $trades): int
    {
        $quantity = 0;
        $value = 0;

        foreach ($trades as $trade) {
            $quantity += $trade->quantity_fine_mg;
            $value += $trade->quantity_fine_mg * $trade->price_per_gram_rial;
        }

        return $quantity === 0 ? 0 : intdiv($value, $quantity);
    }
}
