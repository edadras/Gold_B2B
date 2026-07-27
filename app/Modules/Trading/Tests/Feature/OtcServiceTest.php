<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Commands\CreateOtcOfferCommand;
use App\Modules\Trading\Application\OtcService;
use App\Modules\Trading\Domain\Exceptions\OtcNegotiationException;
use App\Modules\Trading\Domain\OtcOfferStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TradeSource;
use App\Modules\Trading\Infrastructure\Models\OtcOffer;
use App\Modules\Trading\Infrastructure\Models\OtcOfferHistory;
use App\Modules\Trading\Tests\TradingTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The bilateral channel of docs/03-domain/04-trading.md §4.6, including the
 * counter-offer ladder the section draws:
 *
 *   A: sell 500 g @ 78,500,000
 *   B: counter        78,450,000
 *   A: counter        78,480,000
 *   B: accept         -> Trade
 */
final class OtcServiceTest extends TradingTestCase
{
    use RefreshDatabase;

    private const QUANTITY_MG = 500_000;

    private const OPENING_PRICE = 78_500_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG);

        $this->depositGold(self::SELLER_ORG, 2_000_000);
        $this->depositRial(self::BUYER_ORG, 100_000_000_000);
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function creating_an_offer_reserves_the_initiators_gold(): void
    {
        $offer = $this->createOffer();

        $this->assertSame(OtcOfferStatus::PENDING, $offer->status);
        $this->assertSame(self::QUANTITY_MG, $offer->reserved_amount);
        $this->assertNotNull($offer->reservation_entry_id);

        // Step 2 of the §4.6 sequence.
        $this->assertSame(self::QUANTITY_MG, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));
        $this->assertSame(1_500_000, $this->goldBalance(self::SELLER_ORG));

        $this->assertEveryLedgerGroupBalances();
    }

    #[Test]
    public function the_whole_negotiation_ladder_is_recorded_and_ends_in_a_trade(): void
    {
        $offer = $this->createOffer();
        $otc = $this->otc();

        $otc->counter(
            $offer->id,
            self::BUYER_ORG,
            self::BUYER_USER,
            FineWeight::fromMilligrams(self::QUANTITY_MG),
            PricePerFineGram::fromRial(78_450_000),
        );

        $otc->counter(
            $offer->id,
            self::SELLER_ORG,
            self::SELLER_USER,
            FineWeight::fromMilligrams(self::QUANTITY_MG),
            PricePerFineGram::fromRial(78_480_000),
        );

        $trade = $otc->accept($offer->id, self::BUYER_ORG, self::BUYER_USER);

        $this->assertSame(TradeSource::OTC, $trade->trade_source);
        $this->assertSame(78_480_000, $trade->price_per_gram_rial);
        $this->assertSame(self::SELLER_ORG, $trade->seller_organization_id);
        $this->assertSame(self::BUYER_ORG, $trade->buyer_organization_id);
        // Off the book there is no resting order, so no maker side.
        $this->assertNull($trade->maker_side);

        $fresh = OtcOffer::query()->findOrFail($offer->id);
        $this->assertSame(OtcOfferStatus::ACCEPTED, $fresh->status);
        $this->assertSame(2, $fresh->round_count);
        $this->assertSame($trade->id, $fresh->trade_id);

        // Every step, for audit and dispute resolution.
        $history = OtcOfferHistory::query()
            ->where('otc_offer_id', $offer->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(
            [
                OtcOfferHistory::ACTION_CREATED,
                OtcOfferHistory::ACTION_COUNTERED,
                OtcOfferHistory::ACTION_COUNTERED,
                OtcOfferHistory::ACTION_ACCEPTED,
            ],
            $history,
        );
    }

    #[Test]
    public function the_party_whose_terms_are_on_the_table_cannot_accept_them(): void
    {
        $offer = $this->createOffer();

        $this->expectException(OtcNegotiationException::class);
        $this->expectExceptionMessage('proposed the current terms');

        $this->otc()->accept($offer->id, self::SELLER_ORG, self::SELLER_USER);
    }

    #[Test]
    public function a_stranger_cannot_touch_the_offer(): void
    {
        $offer = $this->createOffer();

        $this->expectException(OtcNegotiationException::class);
        $this->expectExceptionMessage('is not a party');

        $this->otc()->accept($offer->id, 999, 1);
    }

    #[Test]
    public function the_negotiation_stops_after_five_round_trips(): void
    {
        $offer = $this->createOffer();
        $otc = $this->otc();

        // Five counters alternate between the two sides, as §4.6 draws them.
        for ($round = 1; $round <= 5; $round++) {
            $actorOrg = $round % 2 === 1 ? self::BUYER_ORG : self::SELLER_ORG;
            $actorUser = $round % 2 === 1 ? self::BUYER_USER : self::SELLER_USER;

            $otc->counter(
                $offer->id,
                $actorOrg,
                $actorUser,
                FineWeight::fromMilligrams(self::QUANTITY_MG),
                PricePerFineGram::fromRial(78_450_000 + $round * 10_000),
            );
        }

        $this->assertSame(5, OtcOffer::query()->findOrFail($offer->id)->round_count);

        $this->expectException(OtcNegotiationException::class);
        $this->expectExceptionMessage('exhausted its 5 counter-offer rounds');

        $otc->counter(
            $offer->id,
            self::SELLER_ORG,
            self::SELLER_USER,
            FineWeight::fromMilligrams(self::QUANTITY_MG),
            PricePerFineGram::fromRial(78_520_000),
        );
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function rejecting_an_offer_hands_the_reservation_straight_back(): void
    {
        $offer = $this->createOffer();

        $this->otc()->reject($offer->id, self::BUYER_ORG, self::BUYER_USER, 'price too high');

        $this->assertSame(
            OtcOfferStatus::REJECTED,
            OtcOffer::query()->findOrFail($offer->id)->status,
        );

        $this->assertSame(0, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));
        $this->assertSame(2_000_000, $this->goldBalance(self::SELLER_ORG));

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function an_expired_offer_releases_its_lock(): void
    {
        $offer = $this->createOffer();

        // Wind the clock past the offer window.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));

        try {
            $expired = $this->otc()->expireStale();

            $this->assertSame(1, $expired);
            $this->assertSame(
                OtcOfferStatus::EXPIRED,
                OtcOffer::query()->findOrFail($offer->id)->status,
            );
            $this->assertSame(0, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));
            $this->assertSystemConserved();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function a_counter_that_changes_the_quantity_resizes_the_initiators_lock(): void
    {
        $offer = $this->createOffer();

        $this->otc()->counter(
            $offer->id,
            self::BUYER_ORG,
            self::BUYER_USER,
            FineWeight::fromMilligrams(300_000),
            PricePerFineGram::fromRial(78_450_000),
        );

        $fresh = OtcOffer::query()->findOrFail($offer->id);

        $this->assertSame(300_000, $fresh->quantity_mg);
        $this->assertSame(300_000, $fresh->reserved_amount);
        $this->assertSame(300_000, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));
        $this->assertSame(1_700_000, $this->goldBalance(self::SELLER_ORG));
    }

    private function createOffer(): OtcOffer
    {
        return $this->otc()->createOffer(new CreateOtcOfferCommand(
            organizationId: self::SELLER_ORG,
            counterpartyOrganizationId: self::BUYER_ORG,
            userId: self::SELLER_USER,
            instrumentCode: self::INSTRUMENT,
            side: Side::SELL,
            quantity: FineWeight::fromMilligrams(self::QUANTITY_MG),
            price: PricePerFineGram::fromRial(self::OPENING_PRICE),
            expiresAt: CarbonImmutable::now()->addMinutes(30),
        ));
    }

    private function otc(): OtcService
    {
        return $this->app->make(OtcService::class);
    }
}
