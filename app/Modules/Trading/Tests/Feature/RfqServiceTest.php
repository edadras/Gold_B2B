<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Commands\CreateRfqCommand;
use App\Modules\Trading\Application\RfqService;
use App\Modules\Trading\Domain\Exceptions\RfqException;
use App\Modules\Trading\Domain\RfqQuoteStatus;
use App\Modules\Trading\Domain\RfqStatus;
use App\Modules\Trading\Domain\RfqVisibility;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TradeSource;
use App\Modules\Trading\Infrastructure\Models\Rfq;
use App\Modules\Trading\Infrastructure\Models\RfqQuote;
use App\Modules\Trading\Tests\TradingTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Request for quote, docs/03-domain/04-trading.md §4.7 — in particular the soft
 * reservation and what happens when it cannot be hardened.
 */
final class RfqServiceTest extends TradingTestCase
{
    use RefreshDatabase;

    private const QUANTITY_MG = 1_000_000;

    private const QUOTE_PRICE = 78_480_000;

    /** The requester buys, so BUYER_ORG asks and SELLER_ORG quotes. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG);

        $this->depositGold(self::SELLER_ORG, 3_000_000);
        $this->depositRial(self::BUYER_ORG, 500_000_000_000);
    }

    #[Test]
    public function a_quote_records_a_soft_reservation_without_locking_anything(): void
    {
        $rfq = $this->createRfq();

        $quote = $this->quote($rfq, self::QUANTITY_MG);

        $this->assertSame(RfqQuoteStatus::PENDING, $quote->status);
        // Rule 1: the balance is untouched and still usable in the order book.
        $this->assertSame(self::QUANTITY_MG, $quote->soft_reserved_mg);
        $this->assertNull($quote->hard_reservation_entry_id);
        $this->assertSame(0, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));
        $this->assertSame(3_000_000, $this->goldBalance(self::SELLER_ORG));

        $this->assertSame(RfqStatus::QUOTED, Rfq::query()->findOrFail($rfq->id)->status);
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function accepting_a_quote_hardens_the_lock_and_writes_a_trade(): void
    {
        $rfq = $this->createRfq();
        $quote = $this->quote($rfq, self::QUANTITY_MG);

        $trade = $this->rfq()->accept($rfq->id, $quote->id, self::BUYER_ORG);

        $this->assertSame(TradeSource::RFQ, $trade->trade_source);
        $this->assertSame(self::QUOTE_PRICE, $trade->price_per_gram_rial);
        $this->assertSame(self::SELLER_ORG, $trade->seller_organization_id);
        $this->assertSame(self::BUYER_ORG, $trade->buyer_organization_id);
        $this->assertSame(self::QUANTITY_MG, $trade->quantity_fine_mg);

        $fresh = RfqQuote::query()->findOrFail($quote->id);
        $this->assertSame(RfqQuoteStatus::ACCEPTED, $fresh->status);
        $this->assertNotNull($fresh->hard_reservation_entry_id);

        $this->assertSame(RfqStatus::ACCEPTED, Rfq::query()->findOrFail($rfq->id)->status);

        // Rule 2, the happy path: soft became hard. Settlement may already have
        // advanced it to IN_SETTLEMENT, so both buckets count.
        $locked = $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED)
            + $this->goldBalance(self::SELLER_ORG, Bucket::IN_SETTLEMENT);
        $this->assertSame(self::QUANTITY_MG, $locked);

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    public function a_partial_acceptance_leaves_the_rfq_open_for_the_rest(): void
    {
        $rfq = $this->createRfq();
        $quote = $this->quote($rfq, self::QUANTITY_MG);

        $trade = $this->rfq()->accept(
            $rfq->id,
            $quote->id,
            self::BUYER_ORG,
            FineWeight::fromMilligrams(400_000),
        );

        $this->assertSame(400_000, $trade->quantity_fine_mg);

        $fresh = Rfq::query()->findOrFail($rfq->id);
        $this->assertSame(RfqStatus::PARTIALLY_ACCEPTED, $fresh->status);
        $this->assertSame(400_000, $fresh->accepted_mg);
        $this->assertSame(600_000, $fresh->remainingMg());
    }

    /**
     * Rule 2's unhappy path: the quoter sold the metal elsewhere between
     * quoting and being accepted.
     *
     * The soft reservation is deliberately not a lock, so this is a real
     * possibility rather than a defensive branch — which is why it must fail
     * cleanly, with no trade and no half-applied reservation.
     */
    #[Test]
    #[Group('ledger-invariants')]
    public function accepting_fails_cleanly_when_the_quoters_balance_was_consumed(): void
    {
        $rfq = $this->createRfq();
        $quote = $this->quote($rfq, self::QUANTITY_MG);

        // The quoter's gold leaves for the order book in the meantime.
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 2_500_000, self::QUOTE_PRICE + 100_000);

        $this->assertSame(500_000, $this->goldBalance(self::SELLER_ORG));

        try {
            $this->rfq()->accept($rfq->id, $quote->id, self::BUYER_ORG);
            $this->fail('Accepting an unfunded quote should have been refused');
        } catch (RfqException $e) {
            $this->assertSame('RFQ_SOFT_RESERVATION_LOST', $e->errorCode());
        }

        // The quote is closed out with a reason, so the requester can move on.
        $fresh = RfqQuote::query()->findOrFail($quote->id);
        $this->assertSame(RfqQuoteStatus::REJECTED, $fresh->status);
        $this->assertStringContainsString('balance consumed', (string) $fresh->reject_reason);

        // Nothing was half-applied: the RFQ is untouched and no trade exists.
        $this->assertSame(0, $fresh->accepted_mg);
        $this->assertSame(0, Rfq::query()->findOrFail($rfq->id)->accepted_mg);
        $this->assertSame(RfqStatus::QUOTED, Rfq::query()->findOrFail($rfq->id)->status);

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    public function a_member_cannot_quote_its_own_request(): void
    {
        $rfq = $this->createRfq();

        $this->expectException(RfqException::class);
        $this->expectExceptionMessage('cannot quote its own RFQ');

        $this->rfq()->quote(
            $rfq->id,
            self::BUYER_ORG,
            self::BUYER_USER,
            FineWeight::fromMilligrams(self::QUANTITY_MG),
            PricePerFineGram::fromRial(self::QUOTE_PRICE),
            CarbonImmutable::now()->addMinutes(10),
        );
    }

    #[Test]
    public function an_uninvited_member_cannot_quote_a_selected_rfq(): void
    {
        $rfq = $this->rfq()->create(new CreateRfqCommand(
            organizationId: self::BUYER_ORG,
            userId: self::BUYER_USER,
            instrumentCode: self::INSTRUMENT,
            side: Side::BUY,
            quantity: FineWeight::fromMilligrams(self::QUANTITY_MG),
            expiresAt: CarbonImmutable::now()->addMinutes(15),
            visibility: RfqVisibility::SELECTED,
            recipientOrgIds: [999],
        ));

        $this->expectException(RfqException::class);
        $this->expectExceptionMessage('is not a recipient');

        $this->quote($rfq, self::QUANTITY_MG);
    }

    #[Test]
    public function expiry_closes_the_request_and_every_quote_under_it(): void
    {
        $rfq = $this->createRfq();
        $quote = $this->quote($rfq, self::QUANTITY_MG);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        try {
            $this->assertSame(1, $this->rfq()->expireStale());

            $this->assertSame(RfqStatus::EXPIRED, Rfq::query()->findOrFail($rfq->id)->status);
            $this->assertSame(RfqQuoteStatus::EXPIRED, RfqQuote::query()->findOrFail($quote->id)->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function a_quote_may_be_withdrawn_before_it_is_accepted(): void
    {
        $rfq = $this->createRfq();
        $quote = $this->quote($rfq, self::QUANTITY_MG);

        $withdrawn = $this->rfq()->withdrawQuote($quote->id, self::SELLER_ORG);

        $this->assertSame(RfqQuoteStatus::WITHDRAWN, $withdrawn->status);
    }

    private function createRfq(): Rfq
    {
        return $this->rfq()->create(new CreateRfqCommand(
            organizationId: self::BUYER_ORG,
            userId: self::BUYER_USER,
            instrumentCode: self::INSTRUMENT,
            side: Side::BUY,
            quantity: FineWeight::fromMilligrams(self::QUANTITY_MG),
            expiresAt: CarbonImmutable::now()->addMinutes(15),
        ));
    }

    private function quote(Rfq $rfq, int $quantityMg): RfqQuote
    {
        return $this->rfq()->quote(
            $rfq->id,
            self::SELLER_ORG,
            self::SELLER_USER,
            FineWeight::fromMilligrams($quantityMg),
            PricePerFineGram::fromRial(self::QUOTE_PRICE),
            CarbonImmutable::now()->addMinutes(10),
        );
    }

    private function rfq(): RfqService
    {
        return $this->app->make(RfqService::class);
    }
}
