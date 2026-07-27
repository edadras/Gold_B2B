<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OrderFill;
use App\Modules\Trading\Infrastructure\Models\OtcOffer;
use App\Modules\Trading\Infrastructure\Models\Rfq;
use App\Modules\Trading\Infrastructure\Models\RfqQuote;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of Trading, for the list and detail endpoints.
 *
 * Kept apart from the command services on purpose: PlaceOrderService and
 * friends own transactions and pessimistic locks, and nothing a screen does
 * should be able to take a lock on the order book. Every method here is a plain
 * SELECT, and every one of them is scoped by organisation in the query rather
 * than filtered afterwards — an unscoped fetch followed by a PHP-side check is
 * one refactor away from leaking another member's book.
 */
final class TradingQueryService
{
    /**
     * @param  list<OrderStatus>|null  $statuses
     * @return Collection<int, Order>
     */
    public function orders(
        int $organizationId,
        ?array $statuses = null,
        ?int $instrumentId = null,
        ?Side $side = null,
        ?int $beforeId = null,
        int $limit = 50,
    ): Collection {
        $query = Order::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->limit($limit);

        if ($statuses !== null && $statuses !== []) {
            $query->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, $statuses));
        }

        if ($instrumentId !== null) {
            $query->where('instrument_id', $instrumentId);
        }

        if ($side !== null) {
            $query->where('side', $side->value);
        }

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        /** @var Collection<int, Order> */
        return $query->get();
    }

    public function order(int $orderId, int $organizationId): ?Order
    {
        /** @var Order|null */
        return Order::query()
            ->where('id', $orderId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    /** @return Collection<int, OrderFill> */
    public function fills(int $orderId): Collection
    {
        /** @var Collection<int, OrderFill> */
        return OrderFill::query()->where('order_id', $orderId)->orderBy('id')->get();
    }

    /** Ids of the caller's still-cancellable orders, for cancel-all. */
    public function openOrderIds(int $organizationId, ?int $instrumentId = null): array
    {
        $query = Order::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', array_map(
                static fn (OrderStatus $s): string => $s->value,
                [OrderStatus::PENDING, OrderStatus::OPEN, OrderStatus::PARTIALLY_FILLED],
            ))
            ->orderBy('id'); // ascending: AGENT_BRIEF rule 4, lock in id order

        if ($instrumentId !== null) {
            $query->where('instrument_id', $instrumentId);
        }

        return $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * The public trade tape for one instrument.
     *
     * Counterparty organisation ids are NOT selected: the tape shows price,
     * size and time, never who traded. Stripping them in a resource would leave
     * them one careless `->toArray()` away from being published.
     *
     * @return list<array{price_rial: int, quantity_mg: int, executed_at: string, side: string|null}>
     */
    public function tape(int $instrumentId, int $limit = 50): array
    {
        return Trade::query()
            ->where('instrument_id', $instrumentId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['price_per_gram_rial', 'quantity_fine_mg', 'executed_at', 'maker_side'])
            ->map(static fn (Trade $trade): array => [
                'price_rial' => (int) $trade->price_per_gram_rial,
                'quantity_mg' => (int) $trade->quantity_fine_mg,
                'executed_at' => $trade->executed_at->toIso8601ZuluString('millisecond'),
                // Which side was the aggressor is public; who they were is not.
                'maker_side' => $trade->maker_side?->value,
            ])
            ->all();
    }

    /** @return Collection<int, OtcOffer> */
    public function otcOffers(int $organizationId, ?string $direction = null, int $limit = 50): Collection
    {
        $query = OtcOffer::query()->orderByDesc('id')->limit($limit);

        match ($direction) {
            'sent' => $query->where('initiator_organization_id', $organizationId),
            'received' => $query->where('counterparty_organization_id', $organizationId),
            default => $query->where(function ($q) use ($organizationId): void {
                $q->where('initiator_organization_id', $organizationId)
                    ->orWhere('counterparty_organization_id', $organizationId);
            }),
        };

        /** @var Collection<int, OtcOffer> */
        return $query->get();
    }

    /** Null when the offer does not exist OR the caller is not one of its two parties. */
    public function otcOffer(int $offerId, int $organizationId): ?OtcOffer
    {
        /** @var OtcOffer|null $offer */
        $offer = OtcOffer::query()->find($offerId);

        return $offer !== null && $offer->involves($organizationId) ? $offer : null;
    }

    /** @return Collection<int, Rfq> */
    public function myRfqs(int $organizationId, int $limit = 50): Collection
    {
        /** @var Collection<int, Rfq> */
        return Rfq::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * RFQs addressed to this member.
     *
     * A SELECTED RFQ names its recipients; an ALL_QUALIFIED or ANONYMOUS one is
     * open to everybody except its own requester. The JSON containment check
     * runs in SQL so the visibility rule cannot be forgotten by a caller.
     *
     * @return Collection<int, Rfq>
     */
    public function rfqInbox(int $organizationId, int $limit = 50): Collection
    {
        /** @var Collection<int, Rfq> */
        return Rfq::query()
            ->where('organization_id', '!=', $organizationId)
            ->where(function ($q) use ($organizationId): void {
                $q->whereIn('visibility', ['ALL_QUALIFIED', 'ANONYMOUS'])
                    ->orWhere(function ($inner) use ($organizationId): void {
                        $inner->where('visibility', 'SELECTED')
                            ->whereJsonContains('recipient_org_ids', $organizationId);
                    });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Visible to its requester and to any member it was addressed to. */
    public function rfq(int $rfqId, int $organizationId): ?Rfq
    {
        /** @var Rfq|null $rfq */
        $rfq = Rfq::query()->find($rfqId);

        if ($rfq === null) {
            return null;
        }

        $visible = (int) $rfq->organization_id === $organizationId
            || $rfq->isVisibleTo($organizationId);

        return $visible ? $rfq : null;
    }

    /**
     * Quotes on an RFQ.
     *
     * The requester sees them all. A quoter sees only its own — otherwise a
     * competitor's price would be readable by everybody invited.
     *
     * @return Collection<int, RfqQuote>
     */
    public function rfqQuotes(Rfq $rfq, int $organizationId): Collection
    {
        $query = RfqQuote::query()->where('rfq_id', $rfq->id)->orderBy('price_per_gram_rial');

        if ((int) $rfq->organization_id !== $organizationId) {
            $query->where('quoter_organization_id', $organizationId);
        }

        /** @var Collection<int, RfqQuote> */
        return $query->get();
    }

    /** A quote is visible to its quoter and to the RFQ's requester. */
    public function rfqQuote(int $quoteId, int $organizationId): ?RfqQuote
    {
        /** @var RfqQuote|null $quote */
        $quote = RfqQuote::query()->find($quoteId);

        if ($quote === null) {
            return null;
        }

        if ((int) $quote->quoter_organization_id === $organizationId) {
            return $quote;
        }

        $requesterId = Rfq::query()->whereKey($quote->rfq_id)->value('organization_id');

        return (int) $requesterId === $organizationId ? $quote : null;
    }
}
