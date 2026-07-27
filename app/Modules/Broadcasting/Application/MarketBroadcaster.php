<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Application;

use App\Modules\Broadcasting\Contracts\InstrumentSymbols;
use App\Modules\Broadcasting\Events\DepthUpdated;
use App\Modules\Broadcasting\Events\MarketStatusChanged;
use App\Modules\Broadcasting\Events\PublicTradeExecuted;
use App\Modules\Broadcasting\Events\QuoteUpdated;
use App\Modules\Broadcasting\Events\ReferencePriceUpdated;

/**
 * The public market feed (§3.2 «کانال‌های عمومی»).
 *
 * Also the seam for whoever owns the order book. The listeners in this module
 * learn about a trade or an order from a domain event, but a domain event does
 * not carry a depth ladder — nothing in Trading's event payloads does — and
 * Broadcasting may not reach into Trading to read one. So depth() is a plain
 * public method: the matching engine, a book publisher command, or a future
 * `OrderBookChanged` event feeds it the ladder it already has in hand, and the
 * throttling, channel naming and payload narrowing all happen here.
 *
 * Every method takes an instrument ID and resolves it to the code, because the
 * channel is `market.GOLD-995-T0` and an unresolvable id must produce silence
 * rather than a channel named after an internal key.
 */
final class MarketBroadcaster
{
    public function __construct(
        private readonly BroadcastGateway $gateway,
        private readonly InstrumentSymbols $instruments,
    ) {}

    /**
     * Top of book. Throttled to 5/sec per instrument.
     *
     * Every price argument is nullable and nulls are omitted from the payload,
     * which is §3.5's "only the changed fields": a listener that knows the last
     * traded price but not the resting bid sends the one it knows.
     *
     * @return bool whether the frame was broadcast or superseded
     */
    public function quote(
        int $instrumentId,
        ?int $bestBidRial = null,
        ?int $bestBidQtyMg = null,
        ?int $bestAskRial = null,
        ?int $bestAskQtyMg = null,
        ?int $lastPriceRial = null,
        ?int $dayChangeBps = null,
        ?string $timestamp = null,
    ): bool {
        $code = $this->instruments->codeFor($instrumentId);

        if ($code === null) {
            return false;
        }

        return $this->gateway->emit(new QuoteUpdated(
            instrumentCode: $code,
            bestBidRial: $bestBidRial,
            bestBidQtyMg: $bestBidQtyMg,
            bestAskRial: $bestAskRial,
            bestAskQtyMg: $bestAskQtyMg,
            lastPriceRial: $lastPriceRial,
            dayChangeBps: $dayChangeBps,
            timestamp: $timestamp ?? $this->now(),
        ));
    }

    /**
     * The ladder. Throttled to 10/sec per instrument, aggregated in 100ms.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $bids
     * @param  list<array{0: int, 1: int, 2: int}>  $asks
     */
    public function depth(int $instrumentId, array $bids, array $asks, ?string $timestamp = null): bool
    {
        $code = $this->instruments->codeFor($instrumentId);

        if ($code === null) {
            return false;
        }

        return $this->gateway->emit(new DepthUpdated(
            instrumentCode: $code,
            bids: $bids,
            asks: $asks,
            timestamp: $timestamp ?? $this->now(),
        ));
    }

    /** The tape. Never throttled — every print goes out. */
    public function tradePrint(
        int $instrumentId,
        int $priceRial,
        int $quantityMg,
        ?string $takerSide,
        string $executedAt,
    ): bool {
        $code = $this->instruments->codeFor($instrumentId);

        if ($code === null) {
            return false;
        }

        return $this->gateway->emit(new PublicTradeExecuted(
            instrumentCode: $code,
            priceRial: $priceRial,
            quantityMg: $quantityMg,
            takerSide: $takerSide,
            executedAt: $executedAt,
        ));
    }

    /**
     * Session state and halts, on `market.status`. Never throttled.
     *
     * The instrument id is optional: a platform-wide halt has no instrument,
     * and an unresolvable one still publishes — a halt with a missing code is
     * far better than no halt at all, which is the opposite of the trade-off
     * made for the per-instrument channels above.
     */
    public function status(
        ?int $instrumentId,
        string $status,
        ?string $reasonCode = null,
        ?string $reason = null,
        ?string $resumeAt = null,
        ?string $sessionDate = null,
        ?int $referencePriceRial = null,
        ?string $timestamp = null,
    ): bool {
        return $this->gateway->emit(new MarketStatusChanged(
            instrumentCode: $instrumentId === null ? null : $this->instruments->codeFor($instrumentId),
            status: $status,
            reasonCode: $reasonCode,
            reason: $reason,
            resumeAt: $resumeAt,
            sessionDate: $sessionDate,
            referencePriceRial: $referencePriceRial,
            timestamp: $timestamp ?? $this->now(),
        ));
    }

    /** The reference price / ounce / FX feed, on `reference-price`. */
    public function referencePrice(
        string $priceType,
        int $valueRial,
        ?int $effectiveValueRial = null,
        ?string $observedAt = null,
    ): bool {
        return $this->gateway->emit(new ReferencePriceUpdated(
            priceType: $priceType,
            valueRial: $valueRial,
            effectiveValueRial: $effectiveValueRial,
            observedAt: $observedAt ?? $this->now(),
        ));
    }

    /** Millisecond ISO-8601 UTC, matching the timestamps in §3.3. */
    private function now(): string
    {
        return now()->toIso8601ZuluString('millisecond');
    }
}
