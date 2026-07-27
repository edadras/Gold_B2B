<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Pricing\Contracts\QuoteWriterInterface;
use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Contracts\TradeSource as PricingTradeSource;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Risk\Contracts\TradeIntent;
use App\Modules\Risk\Contracts\TradeSide;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Commands\CreateOtcOfferCommand;
use App\Modules\Trading\Domain\Exceptions\OtcNegotiationException;
use App\Modules\Trading\Domain\Exceptions\TradingEntityNotFoundException;
use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\OtcOfferStatus;
use App\Modules\Trading\Domain\TradeSource;
use App\Modules\Trading\Events\OtcOfferAccepted;
use App\Modules\Trading\Events\OtcOfferCountered;
use App\Modules\Trading\Events\OtcOfferCreated;
use App\Modules\Trading\Events\OtcOfferRejected;
use App\Modules\Trading\Infrastructure\Models\OtcOffer;
use App\Modules\Trading\Infrastructure\Models\OtcOfferHistory;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bilateral, named-counterparty trading (docs/03-domain/04-trading.md §4.6).
 *
 * How it differs from the book, and why the code looks different too:
 *
 *   · the counterparty is chosen, not discovered, so there is no matching and
 *     no anonymity to protect;
 *   · the price is negotiated, so there is a counter-offer loop — capped at
 *     five round trips, after which the offer expires;
 *   · every step is written to otc_offer_history, because an OTC dispute is
 *     resolved by reading that history and nothing else.
 *
 * Reservations follow the sequence diagram of §4.6: the initiator locks its
 * obligation when the offer goes out (step 2), the acceptor locks its own at
 * acceptance (step 5). A counter-offer re-sizes the initiator's lock by
 * releasing it and taking a fresh one, so the entry that is finally settled
 * always corresponds to the terms that were actually agreed.
 */
final readonly class OtcService
{
    public function __construct(
        private InstrumentRepository $instruments,
        private ObligationReserver $reserver,
        private TradeWriter $tradeWriter,
        private RiskGuardInterface $risk,
        private QuoteWriterInterface $quotes,
    ) {}

    /** Step 1 and 2: send the offer and lock the initiator's side. */
    public function createOffer(CreateOtcOfferCommand $command): OtcOffer
    {
        $instrument = $this->instruments->findByCodeOrFail($command->instrumentCode);

        $this->risk->assertTradeAllowed(new TradeIntent(
            organizationId: $command->organizationId,
            userId: $command->userId,
            side: $command->side->isBuy() ? TradeSide::BUY : TradeSide::SELL,
            fineWeightMg: $command->quantity->milligrams,
            priceRial: $command->price->rial,
            settlementType: $instrument->settlement_type->value,
            counterpartyOrgId: $command->counterpartyOrganizationId,
        ));

        $offer = DB::transaction(function () use ($command, $instrument): OtcOffer {
            $offer = new OtcOffer([
                'offer_code' => 'OTC-PENDING',
                'instrument_id' => $instrument->id,
                'initiator_organization_id' => $command->organizationId,
                'counterparty_organization_id' => $command->counterpartyOrganizationId,
                'proposer_organization_id' => $command->organizationId,
                'created_by_user_id' => $command->userId,
                'side' => $command->side,
                'quantity_mg' => $command->quantity->milligrams,
                'price_rial' => $command->price->rial,
                'min_purity_x10' => $command->minPurityX10 ?? $instrument->min_purity_x10,
                'settlement_type' => $instrument->settlement_type,
                'delivery_type' => $command->deliveryType ?? DeliveryType::CUSTODY_CHANGE,
                'status' => OtcOfferStatus::PENDING,
                'round_count' => 0,
                'max_rounds' => $this->maxRounds(),
                'expires_at' => $command->expiresAt,
            ]);

            $offer->save();
            $offer->offer_code = CodeGenerator::otcOffer($offer->id);
            $offer->save();

            [$entryId, $amount] = $this->reserver->reserve(
                $command->organizationId,
                $command->side,
                $command->quantity,
                $command->price,
                LedgerReference::of('otc_offer', $offer->id),
            );

            $offer->reservation_entry_id = $entryId->value;
            $offer->reserved_amount = $amount;
            $offer->save();

            $this->log($offer, OtcOfferHistory::ACTION_CREATED, $command->organizationId, $command->userId);

            return $offer;
        }, 3);

        event(new OtcOfferCreated(
            offerId: $offer->id,
            offerCode: $offer->offer_code,
            instrumentId: $offer->instrument_id,
            initiatorOrganizationId: $offer->initiator_organization_id,
            counterpartyOrganizationId: $offer->counterparty_organization_id,
            side: $offer->side->value,
            quantityMg: $offer->quantity_mg,
            priceRial: $offer->price_rial,
            expiresAt: $offer->expires_at->toIso8601String(),
            occurredAt: now()->toIso8601String(),
        ));

        return $offer;
    }

    /**
     * Re-price the offer. Only the party who is NOT currently proposing may
     * counter, and only while rounds remain.
     */
    public function counter(
        int $offerId,
        int $actorOrganizationId,
        int $userId,
        FineWeight $quantity,
        PricePerFineGram $price,
        ?string $note = null,
    ): OtcOffer {
        $offer = DB::transaction(function () use ($offerId, $actorOrganizationId, $userId, $quantity, $price, $note): OtcOffer {
            $offer = $this->lockNegotiable($offerId, $actorOrganizationId);

            // The cap is checked first: once the negotiation is exhausted it is
            // over for both parties, so that is the more informative refusal.
            if ($offer->round_count >= $offer->max_rounds) {
                throw OtcNegotiationException::roundLimitReached($offerId, $offer->max_rounds);
            }

            if ($offer->proposer_organization_id === $actorOrganizationId) {
                throw OtcNegotiationException::cannotAcceptOwnTerms($offerId, $actorOrganizationId);
            }

            $this->resizeInitiatorReservation($offer, $quantity, $price);

            $offer->quantity_mg = $quantity->milligrams;
            $offer->price_rial = $price->rial;
            $offer->proposer_organization_id = $actorOrganizationId;
            $offer->round_count++;
            $offer->responded_at = now();
            $this->applyStatus($offer, OtcOfferStatus::COUNTERED);

            $this->log(
                $offer,
                OtcOfferHistory::ACTION_COUNTERED,
                $actorOrganizationId,
                $userId,
                $note,
            );

            return $offer;
        }, 3);

        event(new OtcOfferCountered(
            offerId: $offer->id,
            roundNo: $offer->round_count,
            maxRounds: $offer->max_rounds,
            proposerOrganizationId: $offer->proposer_organization_id,
            responderOrganizationId: $offer->responderOrganizationId(),
            quantityMg: $offer->quantity_mg,
            priceRial: $offer->price_rial,
            occurredAt: now()->toIso8601String(),
        ));

        return $offer;
    }

    /** Steps 4 and 5: lock the acceptor's side and write the trade. */
    public function accept(int $offerId, int $actorOrganizationId, int $userId): Trade
    {
        /** @var array{0: OtcOffer, 1: Trade} $result */
        $result = DB::transaction(function () use ($offerId, $actorOrganizationId, $userId): array {
            $offer = $this->lockNegotiable($offerId, $actorOrganizationId);

            if ($offer->proposer_organization_id === $actorOrganizationId) {
                throw OtcNegotiationException::cannotAcceptOwnTerms($offerId, $actorOrganizationId);
            }

            $instrument = $this->instruments->findOrFail($offer->instrument_id);
            $quantity = FineWeight::fromMilligrams($offer->quantity_mg);
            $price = PricePerFineGram::fromRial($offer->price_rial);

            // The acceptor's side is the mirror of the initiator's, because
            // `side` is always stated from the initiator's point of view.
            $acceptorSide = $actorOrganizationId === $offer->initiator_organization_id
                ? $offer->side
                : $offer->side->opposite();

            $this->reserver->reserve(
                $actorOrganizationId,
                $acceptorSide,
                $quantity,
                $price,
                LedgerReference::of('otc_offer', $offer->id),
            );

            $trade = $this->tradeWriter->write(
                instrument: $instrument,
                source: TradeSource::OTC,
                buyerOrganizationId: $offer->buyerOrganizationId(),
                sellerOrganizationId: $offer->sellerOrganizationId(),
                quantity: $quantity,
                price: $price,
                // Whoever's terms were accepted provided the liquidity.
                buyerIsMaker: $offer->proposer_organization_id === $offer->buyerOrganizationId(),
                links: ['otc_offer_id' => $offer->id],
                deliveryType: $offer->delivery_type,
            );

            $offer->trade_id = $trade->id;
            $offer->responded_at = now();
            $offer->closed_at = now();
            $this->applyStatus($offer, OtcOfferStatus::ACCEPTED);

            $this->log($offer, OtcOfferHistory::ACTION_ACCEPTED, $actorOrganizationId, $userId);

            return [$offer, $trade];
        }, 3);

        [$offer, $trade] = $result;

        event(new OtcOfferAccepted(
            offerId: $offer->id,
            tradeId: $trade->id,
            buyerOrganizationId: $trade->buyer_organization_id,
            sellerOrganizationId: $trade->seller_organization_id,
            quantityMg: $trade->quantity_fine_mg,
            priceRial: $trade->price_per_gram_rial,
            rounds: $offer->round_count,
            occurredAt: now()->toIso8601String(),
        ));

        event(TradeEventFactory::executed($trade));

        $this->publishPrint($trade);

        return $trade;
    }

    /** Decline the terms. The initiator's lock comes straight back. */
    public function reject(int $offerId, int $actorOrganizationId, int $userId, ?string $reason = null): OtcOffer
    {
        return $this->close(
            $offerId,
            $actorOrganizationId,
            $userId,
            OtcOfferStatus::REJECTED,
            OtcOfferHistory::ACTION_REJECTED,
            $reason,
        );
    }

    /** Withdraw an offer that has not been answered yet. Initiator only. */
    public function cancel(int $offerId, int $actorOrganizationId, int $userId, ?string $reason = null): OtcOffer
    {
        return $this->close(
            $offerId,
            $actorOrganizationId,
            $userId,
            OtcOfferStatus::CANCELLED,
            OtcOfferHistory::ACTION_CANCELLED,
            $reason,
        );
    }

    /**
     * Expire everything past its deadline and release the locks behind it.
     *
     * @return int how many offers expired
     */
    public function expireStale(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = OtcOffer::query()
            ->whereIn('status', [OtcOfferStatus::PENDING->value, OtcOfferStatus::COUNTERED->value])
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        /** @var list<OtcOfferRejected> $events */
        $events = [];

        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id): ?OtcOfferRejected {
                $offer = OtcOffer::query()->lockForUpdate()->find($id);

                if ($offer === null || ! $offer->status->isNegotiable()) {
                    return null;
                }

                $this->releaseInitiatorReservation($offer);
                $offer->closed_at = now();
                $this->applyStatus($offer, OtcOfferStatus::EXPIRED);

                $this->log(
                    $offer,
                    OtcOfferHistory::ACTION_EXPIRED,
                    $offer->initiator_organization_id,
                    null,
                    'offer window elapsed',
                );

                return new OtcOfferRejected(
                    offerId: $offer->id,
                    actorOrganizationId: $offer->initiator_organization_id,
                    counterpartyOrganizationId: $offer->counterparty_organization_id,
                    status: OtcOfferStatus::EXPIRED->value,
                    reason: 'offer window elapsed',
                    occurredAt: now()->toIso8601String(),
                );
            }, 3);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        foreach ($events as $event) {
            event($event);
        }

        return count($events);
    }

    private function close(
        int $offerId,
        int $actorOrganizationId,
        int $userId,
        OtcOfferStatus $status,
        string $action,
        ?string $reason,
    ): OtcOffer {
        $offer = DB::transaction(function () use ($offerId, $actorOrganizationId, $userId, $status, $action, $reason): OtcOffer {
            $offer = $this->lockNegotiable($offerId, $actorOrganizationId);

            if ($status === OtcOfferStatus::CANCELLED
                && $actorOrganizationId !== $offer->initiator_organization_id) {
                throw OtcNegotiationException::notAParty($offerId, $actorOrganizationId);
            }

            $this->releaseInitiatorReservation($offer);

            $offer->responded_at = now();
            $offer->closed_at = now();
            $this->applyStatus($offer, $status);

            $this->log($offer, $action, $actorOrganizationId, $userId, $reason);

            return $offer;
        }, 3);

        event(new OtcOfferRejected(
            offerId: $offer->id,
            actorOrganizationId: $actorOrganizationId,
            counterpartyOrganizationId: $offer->counterparty_organization_id,
            status: $offer->status->value,
            reason: $reason,
            occurredAt: now()->toIso8601String(),
        ));

        return $offer;
    }


    /**
     * Fold the print into Pricing's day statistics, after commit.
     *
     * An OTC or RFQ print never moves the last price — Pricing enforces that
     * through TradeSource::movesLastPrice() — but it does count towards volume
     * and VWAP (§7.6), so it is handed over like any other.
     */
    private function publishPrint(Trade $trade): void
    {
        $this->quotes->recordTrade(new TradePrint(
            instrumentId: $trade->instrument_id,
            pricePerFineGramRial: $trade->price_per_gram_rial,
            fineWeightMg: $trade->quantity_fine_mg,
            executedAt: $trade->executed_at,
            source: PricingTradeSource::from($trade->trade_source->value),
            tradeId: $trade->id,
        ));
    }

    private function lockNegotiable(int $offerId, int $actorOrganizationId): OtcOffer
    {
        $offer = OtcOffer::query()->lockForUpdate()->find($offerId)
            ?? throw TradingEntityNotFoundException::otcOffer($offerId);

        if (! $offer->involves($actorOrganizationId)) {
            throw OtcNegotiationException::notAParty($offerId, $actorOrganizationId);
        }

        if (! $offer->status->isNegotiable()) {
            throw new InvalidStateTransitionException('OtcOffer', $offer->status->value, 'ACCEPTED');
        }

        if ($offer->expires_at->isPast()) {
            throw OtcNegotiationException::expired($offerId);
        }

        return $offer;
    }

    /**
     * A counter changes the terms, so the initiator's lock has to change with
     * them. Releasing and re-reserving (rather than adjusting in place) keeps a
     * clean audit trail: one reservation entry per set of terms.
     */
    private function resizeInitiatorReservation(OtcOffer $offer, FineWeight $quantity, PricePerFineGram $price): void
    {
        $required = $this->reserver->requirementFor($offer->side, $quantity, $price);

        if ($required === $offer->reserved_amount) {
            return;
        }

        $this->releaseInitiatorReservation($offer);

        [$entryId, $amount] = $this->reserver->reserve(
            $offer->initiator_organization_id,
            $offer->side,
            $quantity,
            $price,
            LedgerReference::of('otc_offer', $offer->id),
        );

        $offer->reservation_entry_id = $entryId->value;
        $offer->reserved_amount = $amount;
    }

    private function releaseInitiatorReservation(OtcOffer $offer): void
    {
        if ($offer->reservation_entry_id === null || $offer->reserved_amount <= 0) {
            return;
        }

        $this->reserver->release(
            $offer->side,
            LedgerEntryId::fromInt($offer->reservation_entry_id),
            $offer->reserved_amount,
        );

        $offer->reservation_entry_id = null;
        $offer->reserved_amount = 0;
    }

    private function applyStatus(OtcOffer $offer, OtcOfferStatus $target): void
    {
        $current = $offer->status;

        if (! $current->canTransitionTo($target)) {
            throw new InvalidStateTransitionException('OtcOffer', $current->value, $target->value);
        }

        $offer->status = $target;
        $offer->save();
    }

    private function log(
        OtcOffer $offer,
        string $action,
        int $actorOrganizationId,
        ?int $actorUserId,
        ?string $note = null,
    ): void {
        OtcOfferHistory::create([
            'otc_offer_id' => $offer->id,
            'round_no' => $offer->round_count,
            'action' => $action,
            'actor_organization_id' => $actorOrganizationId,
            'actor_user_id' => $actorUserId,
            'quantity_mg' => $offer->quantity_mg,
            'price_rial' => $offer->price_rial,
            'note' => $note,
        ]);
    }

    /** "حداکثر ۵ رفت‌وبرگشت، سپس انقضا" — §4.6. */
    private function maxRounds(): int
    {
        $configured = config('goldb2b.trading.otc_max_rounds');

        return is_int($configured) ? $configured : 5;
    }
}
