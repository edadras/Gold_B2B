<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Pricing\Contracts\QuoteWriterInterface;
use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Contracts\TradeSource as PricingTradeSource;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Commands\CreateRfqCommand;
use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\Exceptions\RfqException;
use App\Modules\Trading\Domain\Exceptions\TradingEntityNotFoundException;
use App\Modules\Trading\Domain\RfqQuoteStatus;
use App\Modules\Trading\Domain\RfqStatus;
use App\Modules\Trading\Domain\TradeSource;
use App\Modules\Trading\Events\RfqAccepted;
use App\Modules\Trading\Events\RfqCreated;
use App\Modules\Trading\Events\RfqExpired;
use App\Modules\Trading\Events\RfqQuoted;
use App\Modules\Trading\Infrastructure\Models\Rfq;
use App\Modules\Trading\Infrastructure\Models\RfqQuote;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Request for quote (docs/03-domain/04-trading.md §4.7).
 *
 * The interesting part is the soft reservation, and it is deliberately NOT a
 * ledger lock. Rule 1 of §4.7: a quoter's balance stays fully usable in the
 * order book while its quote is outstanding, and the platform merely warns when
 * a member's soft locks exceed what it holds. Locking for real would let a
 * member freeze its own inventory by quoting widely, which is the opposite of
 * what an RFQ is for.
 *
 * Rule 2 then says what happens at acceptance: the soft lock becomes a hard
 * one, and if the balance was spent in the meantime the quote is refused and
 * the quoter takes a reputation hit. That is the failure this class has to
 * handle cleanly — see accept(), where InsufficientBalanceException is caught,
 * the quote is marked REJECTED with a reason, and the requester gets a domain
 * error instead of a half-built trade.
 */
final readonly class RfqService
{
    public function __construct(
        private InstrumentRepository $instruments,
        private ObligationReserver $reserver,
        private TradeWriter $tradeWriter,
        private GoldLedgerInterface $goldLedger,
        private RialLedgerInterface $rialLedger,
        private QuoteWriterInterface $quotes,
    ) {}

    public function create(CreateRfqCommand $command): Rfq
    {
        $instrument = $this->instruments->findByCodeOrFail($command->instrumentCode);

        $rfq = DB::transaction(function () use ($command, $instrument): Rfq {
            $rfq = new Rfq([
                'rfq_code' => 'RFQ-PENDING',
                'instrument_id' => $instrument->id,
                'organization_id' => $command->organizationId,
                'created_by_user_id' => $command->userId,
                'side' => $command->side,
                'quantity_mg' => $command->quantity->milligrams,
                'accepted_mg' => 0,
                'min_purity_x10' => $command->minPurityX10 ?? $instrument->min_purity_x10,
                'settlement_type' => $instrument->settlement_type,
                'delivery_type' => $command->deliveryType ?? DeliveryType::CUSTODY_CHANGE,
                'visibility' => $command->visibility,
                'recipient_org_ids' => $command->recipientOrgIds === [] ? null : $command->recipientOrgIds,
                'allow_partial' => $command->allowPartial,
                'status' => RfqStatus::OPEN,
                'expires_at' => $command->expiresAt,
            ]);

            $rfq->save();
            $rfq->rfq_code = CodeGenerator::rfq($rfq->id);
            $rfq->save();

            return $rfq;
        }, 3);

        event(new RfqCreated(
            rfqId: $rfq->id,
            rfqCode: $rfq->rfq_code,
            instrumentId: $rfq->instrument_id,
            organizationId: $rfq->organization_id,
            side: $rfq->side->value,
            quantityMg: $rfq->quantity_mg,
            visibility: $rfq->visibility->value,
            recipientOrgIds: $rfq->recipient_org_ids ?? [],
            expiresAt: $rfq->expires_at->toIso8601String(),
            occurredAt: now()->toIso8601String(),
        ));

        return $rfq;
    }

    /**
     * Answer an RFQ. Records a soft reservation only — see the class docblock —
     * and warns when the quoter's outstanding soft locks exceed its balance.
     */
    public function quote(
        int $rfqId,
        int $quoterOrganizationId,
        int $userId,
        FineWeight $quantity,
        PricePerFineGram $price,
        CarbonImmutable $validUntil,
    ): RfqQuote {
        $quote = DB::transaction(function () use ($rfqId, $quoterOrganizationId, $userId, $quantity, $price, $validUntil): RfqQuote {
            $rfq = $this->lockRfq($rfqId);

            if ($rfq->organization_id === $quoterOrganizationId) {
                throw RfqException::selfQuote($rfqId, $quoterOrganizationId);
            }

            if ($rfq->expires_at->isPast()) {
                throw RfqException::expired($rfqId);
            }

            if (! $rfq->status->acceptsQuotes()) {
                throw new InvalidStateTransitionException('Rfq', $rfq->status->value, 'QUOTED');
            }

            if (! $rfq->isVisibleTo($quoterOrganizationId)) {
                throw RfqException::notInvited($rfqId, $quoterOrganizationId);
            }

            // The quoter stands on the opposite side of the requester.
            $quoterSide = $rfq->side->opposite();
            $requirement = $this->reserver->requirementFor($quoterSide, $quantity, $price);

            $quote = new RfqQuote([
                'quote_code' => 'QTE-PENDING',
                'rfq_id' => $rfq->id,
                'quoter_organization_id' => $quoterOrganizationId,
                'quoted_by_user_id' => $userId,
                'quantity_mg' => $quantity->milligrams,
                'accepted_mg' => 0,
                'price_per_gram_rial' => $price->rial,
                'soft_reserved_mg' => $quoterSide->isSell() ? $requirement : 0,
                'soft_reserved_rial' => $quoterSide->isBuy() ? $requirement : 0,
                'status' => RfqQuoteStatus::PENDING,
                'valid_until' => $validUntil,
            ]);

            $quote->save();
            $quote->quote_code = CodeGenerator::quote($quote->id);
            $quote->save();

            $this->warnOnOverSoftReservation($quoterOrganizationId, $quoterSide->isSell());

            if ($rfq->status === RfqStatus::OPEN) {
                $this->applyStatus($rfq, RfqStatus::QUOTED);
            }

            return $quote;
        }, 3);

        $rfq = $quote->rfq()->first();

        event(new RfqQuoted(
            rfqId: $quote->rfq_id,
            quoteId: $quote->id,
            quoteCode: $quote->quote_code,
            quoterOrganizationId: $quote->quoter_organization_id,
            requesterOrganizationId: (int) ($rfq?->organization_id ?? 0),
            quantityMg: $quote->quantity_mg,
            pricePerGramRial: $quote->price_per_gram_rial,
            validUntil: $quote->valid_until->toIso8601String(),
            occurredAt: now()->toIso8601String(),
        ));

        return $quote;
    }

    /**
     * Accept a quote, in whole or in part.
     *
     * This is where the soft lock becomes a hard one for the quoter and the
     * requester locks its own side. If the quoter's balance is gone, nothing is
     * written: the transaction rolls back and a second, tiny transaction marks
     * the quote REJECTED so the requester can move on to the next one.
     *
     * @param  FineWeight|null  $acceptQuantity  null accepts the whole quote
     */
    public function accept(
        int $rfqId,
        int $quoteId,
        int $requesterOrganizationId,
        ?FineWeight $acceptQuantity = null,
    ): Trade {
        /** @var array{0: Rfq, 1: RfqQuote, 2: Trade} $result */
        try {
            $result = DB::transaction(function () use ($rfqId, $quoteId, $requesterOrganizationId, $acceptQuantity): array {
                $rfq = $this->lockRfq($rfqId);

                if ($rfq->organization_id !== $requesterOrganizationId) {
                    throw RfqException::notOwner($rfqId, $requesterOrganizationId);
                }

                if ($rfq->expires_at->isPast()) {
                    throw RfqException::expired($rfqId);
                }

                $quote = RfqQuote::query()->lockForUpdate()->find($quoteId)
                    ?? throw TradingEntityNotFoundException::rfqQuote($quoteId);

                if ($quote->rfq_id !== $rfq->id) {
                    throw TradingEntityNotFoundException::rfqQuote($quoteId);
                }

                if ($quote->status !== RfqQuoteStatus::PENDING) {
                    throw new InvalidStateTransitionException('RfqQuote', $quote->status->value, 'ACCEPTED');
                }

                if ($quote->valid_until->isPast()) {
                    throw RfqException::quoteExpired($quoteId);
                }

                $quantity = $acceptQuantity ?? FineWeight::fromMilligrams($quote->quantity_mg);

                if ($quantity->milligrams < $quote->quantity_mg && ! $rfq->allow_partial) {
                    throw RfqException::partialNotAllowed($rfqId);
                }

                if ($quantity->milligrams > $quote->remainingMg()) {
                    throw RfqException::exceedsRemaining($rfqId, $quantity->milligrams, $quote->remainingMg());
                }

                if ($quantity->milligrams > $rfq->remainingMg()) {
                    throw RfqException::exceedsRemaining($rfqId, $quantity->milligrams, $rfq->remainingMg());
                }

                $instrument = $this->instruments->findOrFail($rfq->instrument_id);
                $price = PricePerFineGram::fromRial($quote->price_per_gram_rial);
                $quoterSide = $rfq->side->opposite();
                $reference = LedgerReference::of('rfq_quote', $quote->id);

                // Rule 2: soft lock -> hard lock. An InsufficientBalance here is
                // the documented failure mode, handled by the catch below.
                [$quoterEntry] = $this->reserver->reserve(
                    $quote->quoter_organization_id,
                    $quoterSide,
                    $quantity,
                    $price,
                    $reference,
                );

                $this->reserver->reserve(
                    $rfq->organization_id,
                    $rfq->side,
                    $quantity,
                    $price,
                    $reference,
                );

                $buyerOrgId = $rfq->side->isBuy() ? $rfq->organization_id : $quote->quoter_organization_id;
                $sellerOrgId = $rfq->side->isSell() ? $rfq->organization_id : $quote->quoter_organization_id;

                $trade = $this->tradeWriter->write(
                    instrument: $instrument,
                    source: TradeSource::RFQ,
                    buyerOrganizationId: $buyerOrgId,
                    sellerOrganizationId: $sellerOrgId,
                    quantity: $quantity,
                    price: $price,
                    // The quoter put the price on the table, so it is the maker.
                    buyerIsMaker: $buyerOrgId === $quote->quoter_organization_id,
                    links: ['rfq_quote_id' => $quote->id],
                    deliveryType: $rfq->delivery_type,
                );

                $quote->accepted_mg += $quantity->milligrams;
                $quote->hard_reservation_entry_id = $quoterEntry->value;
                $quote->trade_id = $trade->id;
                $quote->responded_at = now();
                $quote->status = RfqQuoteStatus::ACCEPTED;
                $quote->save();

                $rfq->accepted_mg += $quantity->milligrams;

                $this->applyStatus(
                    $rfq,
                    $rfq->remainingMg() <= 0 ? RfqStatus::ACCEPTED : RfqStatus::PARTIALLY_ACCEPTED,
                );

                if ($rfq->remainingMg() <= 0) {
                    $rfq->closed_at = now();
                    $rfq->save();
                }

                return [$rfq, $quote, $trade];
            }, 3);
        } catch (InsufficientBalanceException) {
            $this->markQuoteUnfunded($quoteId);

            $quote = RfqQuote::query()->find($quoteId);

            throw RfqException::softReservationLost(
                $quoteId,
                (int) ($quote?->quoter_organization_id ?? 0),
            );
        }

        [$rfq, $quote, $trade] = $result;

        event(new RfqAccepted(
            rfqId: $rfq->id,
            quoteId: $quote->id,
            tradeId: $trade->id,
            requesterOrganizationId: $rfq->organization_id,
            quoterOrganizationId: $quote->quoter_organization_id,
            acceptedMg: $trade->quantity_fine_mg,
            pricePerGramRial: $trade->price_per_gram_rial,
            fullyAccepted: $rfq->status === RfqStatus::ACCEPTED,
            occurredAt: now()->toIso8601String(),
        ));

        event(TradeEventFactory::executed($trade));

        $this->publishPrint($trade);

        return $trade;
    }

    /**
     * Expire RFQs past their window and every quote still pending under them.
     *
     * @return int how many RFQs expired
     */
    public function expireStale(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = Rfq::query()
            ->whereIn('status', [
                RfqStatus::OPEN->value,
                RfqStatus::QUOTED->value,
                RfqStatus::PARTIALLY_ACCEPTED->value,
            ])
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        /** @var list<RfqExpired> $events */
        $events = [];

        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id): ?RfqExpired {
                $rfq = Rfq::query()->lockForUpdate()->find($id);

                if ($rfq === null || $rfq->status->isFinal()) {
                    return null;
                }

                $expiredQuotes = RfqQuote::query()
                    ->where('rfq_id', $rfq->id)
                    ->where('status', RfqQuoteStatus::PENDING->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($expiredQuotes as $quote) {
                    // Soft reservations are not ledger locks, so expiry is a
                    // status change and nothing else has to be unwound.
                    $quote->status = RfqQuoteStatus::EXPIRED;
                    $quote->responded_at = now();
                    $quote->save();
                }

                $rfq->closed_at = now();
                $this->applyStatus($rfq, RfqStatus::EXPIRED);

                return new RfqExpired(
                    rfqId: $rfq->id,
                    organizationId: $rfq->organization_id,
                    quantityMg: $rfq->quantity_mg,
                    acceptedMg: $rfq->accepted_mg,
                    expiredQuoteCount: $expiredQuotes->count(),
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

    /** Quoter pulls a quote back before it is accepted (rule 3 of §4.7). */
    public function withdrawQuote(int $quoteId, int $quoterOrganizationId): RfqQuote
    {
        return DB::transaction(function () use ($quoteId, $quoterOrganizationId): RfqQuote {
            $quote = RfqQuote::query()->lockForUpdate()->find($quoteId)
                ?? throw TradingEntityNotFoundException::rfqQuote($quoteId);

            if ($quote->quoter_organization_id !== $quoterOrganizationId) {
                throw RfqException::notOwner($quote->rfq_id, $quoterOrganizationId);
            }

            if (! $quote->status->canTransitionTo(RfqQuoteStatus::WITHDRAWN)) {
                throw new InvalidStateTransitionException('RfqQuote', $quote->status->value, 'WITHDRAWN');
            }

            $quote->status = RfqQuoteStatus::WITHDRAWN;
            $quote->responded_at = now();
            $quote->save();

            return $quote;
        }, 3);
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

    private function lockRfq(int $rfqId): Rfq
    {
        return Rfq::query()->lockForUpdate()->find($rfqId)
            ?? throw TradingEntityNotFoundException::rfq($rfqId);
    }

    private function applyStatus(Rfq $rfq, RfqStatus $target): void
    {
        $current = $rfq->status;

        if ($current === $target) {
            $rfq->save();

            return;
        }

        if (! $current->canTransitionTo($target)) {
            throw new InvalidStateTransitionException('Rfq', $current->value, $target->value);
        }

        $rfq->status = $target;
        $rfq->save();
    }

    /**
     * A separate, tiny transaction: the one that tried to accept has already
     * rolled back, so the rejection has to be written on its own.
     */
    private function markQuoteUnfunded(int $quoteId): void
    {
        DB::transaction(function () use ($quoteId): void {
            $quote = RfqQuote::query()->lockForUpdate()->find($quoteId);

            if ($quote === null || $quote->status !== RfqQuoteStatus::PENDING) {
                return;
            }

            $quote->status = RfqQuoteStatus::REJECTED;
            $quote->reject_reason = 'soft reservation could not be converted: balance consumed';
            $quote->responded_at = now();
            $quote->save();
        });

        Log::warning('RFQ quote could not be funded at acceptance', ['quote_id' => $quoteId]);
    }

    /**
     * Rule 1 of §4.7: "سیستم هشدار می‌دهد اگر مجموع قفل‌های نرم > موجودی".
     * A warning, never a refusal — the quoter is free to over-quote and manage
     * the risk itself; the platform records that it did.
     */
    private function warnOnOverSoftReservation(int $organizationId, bool $isGold): void
    {
        $column = $isGold ? 'soft_reserved_mg' : 'soft_reserved_rial';

        $outstanding = (int) RfqQuote::query()
            ->where('quoter_organization_id', $organizationId)
            ->where('status', RfqQuoteStatus::PENDING->value)
            ->sum($column);

        $available = $isGold
            ? $this->goldLedger->availableBalance($organizationId)->milligrams
            : $this->rialLedger->availableBalance($organizationId)->amount;

        if ($outstanding > $available) {
            Log::warning('Soft reservations exceed available balance', [
                'organization_id' => $organizationId,
                'asset' => $isGold ? 'GOLD' : 'RIAL',
                'soft_reserved' => $outstanding,
                'available' => $available,
            ]);
        }
    }
}
