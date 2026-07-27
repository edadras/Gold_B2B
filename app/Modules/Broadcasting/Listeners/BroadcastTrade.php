<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MarketBroadcaster;
use App\Modules\Broadcasting\Application\MemberBroadcaster;
use App\Modules\Broadcasting\Contracts\InstrumentSymbols;
use App\Modules\Broadcasting\Domain\EventShape;

/**
 * Trading\Events\TradeExecuted → three broadcasts, on purpose.
 *
 *   1. the anonymous tape print on the public `market.{instrument}` channel;
 *   2. the buyer's full trade, with the seller as counterparty, on
 *      `private-org.{buyerId}`;
 *   3. the seller's full trade, with the buyer as counterparty, on
 *      `private-org.{sellerId}`.
 *
 * (2) and (3) are built separately rather than by broadcasting one payload to
 * two channels. Sharing one would mean each side receives the other's fee and
 * a `side` that is right for only one of them — and would put both
 * organisation ids on both channels, so a member could read their
 * counterparty's identity out of a field meant for their own.
 *
 * A public quote refresh rides along on the last traded price: a print moves
 * the top of book by definition, and the quote is throttled so a burst of
 * fills does not become a burst of frames.
 *
 * THE EVENT IS NEVER TYPE-HINTED. Broadcasting may not import Trading, so the
 * payload is read through EventShape and every required field is checked. An
 * upstream rename degrades this to "no broadcast", never to a fatal inside the
 * dispatcher that would surface as a 500 on the order that caused the fill.
 */
final class BroadcastTrade
{
    public function __construct(
        private readonly MarketBroadcaster $market,
        private readonly MemberBroadcaster $members,
        private readonly InstrumentSymbols $instruments,
    ) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);

        $instrumentId = $shape->int('instrumentId');
        $tradeCode = $shape->string('tradeCode');
        $buyerOrgId = $shape->int('buyerOrganizationId');
        $sellerOrgId = $shape->int('sellerOrganizationId');
        $fineWeightMg = $shape->firstInt(['fineWeightMg', 'quantityFineMg', 'quantityMg']);
        $priceRial = $shape->firstInt(['pricePerGramRial', 'priceRial']);
        $executedAt = $shape->firstString(['executedAt', 'occurredAt']);

        if ($instrumentId === null || $tradeCode === null || $buyerOrgId === null
            || $sellerOrgId === null || $fineWeightMg === null || $priceRial === null) {
            return;
        }

        $executedAt ??= now()->toIso8601ZuluString('millisecond');
        $grossRial = $shape->firstInt(['grossAmountRial', 'grossAmount']) ?? 0;

        // --- 1. the public tape ------------------------------------------
        $this->market->tradePrint(
            instrumentId: $instrumentId,
            priceRial: $priceRial,
            quantityMg: $fineWeightMg,
            takerSide: $this->takerSide($shape),
            executedAt: $executedAt,
        );

        // The print is the newest information about the top of book.
        $this->market->quote(
            instrumentId: $instrumentId,
            lastPriceRial: $priceRial,
            timestamp: $executedAt,
        );

        $instrumentCode = $this->instruments->codeFor($instrumentId);
        $settlementDeadline = $shape->string('settlementDeadline');

        // --- 2. the buyer -------------------------------------------------
        $this->members->tradeExecuted(
            organizationId: $buyerOrgId,
            counterpartyOrganizationId: $sellerOrgId,
            tradeCode: $tradeCode,
            side: 'BUY',
            quantityFineMg: $fineWeightMg,
            pricePerGramRial: $priceRial,
            grossAmountRial: $grossRial,
            feeRial: $shape->intOr('buyerFeeRial', 0),
            netAmountRial: $shape->intOr('buyerNetRial', 0),
            instrumentCode: $instrumentCode,
            settlementCode: $shape->string('settlementCode'),
            settlementDeadline: $settlementDeadline,
            executedAt: $executedAt,
        );

        // --- 3. the seller ------------------------------------------------
        $this->members->tradeExecuted(
            organizationId: $sellerOrgId,
            counterpartyOrganizationId: $buyerOrgId,
            tradeCode: $tradeCode,
            side: 'SELL',
            quantityFineMg: $fineWeightMg,
            pricePerGramRial: $priceRial,
            grossAmountRial: $grossRial,
            feeRial: $shape->intOr('sellerFeeRial', 0),
            netAmountRial: $shape->intOr('sellerNetRial', 0),
            instrumentCode: $instrumentCode,
            settlementCode: $shape->string('settlementCode'),
            settlementDeadline: $settlementDeadline,
            executedAt: $executedAt,
        );
    }

    /**
     * The taker is whichever side did not make the price.
     *
     * `makerSide` is nullable on the domain event (an auction cross has no
     * taker), and null is published as an absent field rather than guessed —
     * §3.3's public payload allows a taker_side, it does not require one.
     */
    private function takerSide(EventShape $shape): ?string
    {
        return match ($shape->string('makerSide')) {
            'BUY' => 'SELL',
            'SELL' => 'BUY',
            default => null,
        };
    }
}
