<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Shared\Calculation\TradeValueCalculator;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\FeeSchedule;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TradeSource;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OrderFill;
use App\Modules\Trading\Tests\TradingTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Worked example 1, end to end — docs/11-appendix/03-worked-examples.md.
 *
 * Organisation 184 sells 250 g fine at 78,480,000; organisation 291 then buys
 * 250 g at 78,500,000. The resting sell is the maker, so the trade prints at
 * 78,480,000 and the buyer keeps the 20,000 rial/gram difference — the rule
 * that rewards liquidity (§4.4).
 *
 * Every figure asserted here is copied from the document, not recomputed from
 * the code: that is the point of the test.
 */
#[Group('ledger-invariants')]
final class WorkedExampleOneTest extends TradingTestCase
{
    use RefreshDatabase;

    private const QUANTITY_MG = 250_000;

    private const SELLER_PRICE = 78_480_000;

    private const BUYER_PRICE = 78_500_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG);

        $this->depositGold(self::SELLER_ORG, 1_000_000);
        $this->depositRial(self::SELLER_ORG, 500_000_000);
        $this->depositRial(self::BUYER_ORG, 25_000_000_000);
    }

    #[Test]
    public function the_seller_order_reserves_gold_and_rests_in_the_book(): void
    {
        $result = $this->placeLimit(
            self::SELLER_ORG,
            self::SELLER_USER,
            Side::SELL,
            self::QUANTITY_MG,
            self::SELLER_PRICE,
        );

        $this->assertSame(OrderStatus::OPEN, $result->order->status);
        $this->assertSame([], $result->trades);
        $this->assertNotNull($result->order->reservation_entry_id);

        // Transaction group g1 of the document.
        $this->assertSame(750_000, $this->goldBalance(self::SELLER_ORG));
        $this->assertSame(250_000, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));

        $this->assertEveryLedgerGroupBalances();
    }

    #[Test]
    public function the_buyer_reserves_at_its_own_price_before_it_knows_the_fill_price(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, self::QUANTITY_MG, self::SELLER_PRICE);

        // g2: gross 19,625,000,000 + fee 29,437,500 = 19,654,437,500 reserved,
        // of which 19,649,430,000 survives the surplus release in g3.
        $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, self::QUANTITY_MG, self::BUYER_PRICE);

        $this->assertSame(19_654_437_500, $buy->order->reserved_amount);
        $this->assertSame(19_649_430_000, $buy->order->consumed_amount);
        $this->assertSame(5_007_500, $buy->order->released_amount);
    }

    #[Test]
    public function the_trade_executes_at_the_makers_price_with_the_documented_amounts(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, self::QUANTITY_MG, self::SELLER_PRICE);
        $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, self::QUANTITY_MG, self::BUYER_PRICE);

        $this->assertCount(1, $buy->trades);
        $trade = $buy->trades[0];

        // The whole point: the maker's price, not the taker's.
        $this->assertSame(self::SELLER_PRICE, $trade->price_per_gram_rial);
        $this->assertSame(Side::SELL, $trade->maker_side);
        $this->assertSame(TradeSource::ORDER_BOOK, $trade->trade_source);

        $this->assertSame(250_000, $trade->quantity_fine_mg);
        $this->assertSame(19_620_000_000, $trade->gross_amount_rial);
        $this->assertSame(29_430_000, $trade->buyer_fee_rial);   // taker, 0.15%
        $this->assertSame(19_620_000, $trade->seller_fee_rial);  // maker, 0.10%
        $this->assertSame(0, $trade->tax_rial);
        $this->assertSame(19_649_430_000, $trade->buyer_net_rial);
        $this->assertSame(19_600_380_000, $trade->seller_net_rial);

        $this->assertSame(self::BUYER_ORG, $trade->buyer_organization_id);
        $this->assertSame(self::SELLER_ORG, $trade->seller_organization_id);
        $this->assertSame('TRD-'.str_pad((string) $trade->id, 8, '0', STR_PAD_LEFT), $trade->trade_code);
    }

    /**
     * Worked example 1's transaction group g3.
     *
     * The reservation is asserted as RESERVED + IN_SETTLEMENT rather than
     * RESERVED alone, because the whole platform is wired in this suite:
     * Settlement listens for TradeExecuted and immediately locks the assets
     * (the document's g4, RESERVED -> IN_SETTLEMENT). What Trading is
     * responsible for is that the surplus never reaches settlement at all —
     * and the buyer's AVAILABLE balance afterwards is exactly the document's
     * closing figure.
     */
    #[Test]
    public function the_buyers_surplus_reservation_is_released(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, self::QUANTITY_MG, self::SELLER_PRICE);
        $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, self::QUANTITY_MG, self::BUYER_PRICE);

        // 19,654,437,500 reserved - 19,649,430,000 needed = 5,007,500 handed back.
        $this->assertSame(5_007_500, $buy->order->released_amount);

        $stillLocked = $this->rialBalance(self::BUYER_ORG, Bucket::RESERVED)
            + $this->rialBalance(self::BUYER_ORG, Bucket::IN_SETTLEMENT);

        $this->assertSame(19_649_430_000, $stillLocked);

        // The document's closing balance for organisation 291.
        $this->assertSame(5_350_570_000, $this->rialBalance(self::BUYER_ORG));

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    public function both_orders_end_filled_and_each_side_has_a_fill_row(): void
    {
        $sell = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, self::QUANTITY_MG, self::SELLER_PRICE);
        $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, self::QUANTITY_MG, self::BUYER_PRICE);

        $this->assertSame(OrderStatus::FILLED, $buy->order->status);
        $this->assertSame(OrderStatus::FILLED, Order::query()->findOrFail($sell->order->id)->status);

        $fills = OrderFill::query()->where('trade_id', $buy->trades[0]->id)->get();
        $this->assertCount(2, $fills);

        $roles = $fills->pluck('role', 'order_id');
        $this->assertSame(OrderFill::ROLE_MAKER, $roles[$sell->order->id]);
        $this->assertSame(OrderFill::ROLE_TAKER, $roles[$buy->order->id]);
    }

    /**
     * The complete example of F5/F8/F9 in docs/11-appendix/01-formulas.md, which
     * prices 250 g GROSS at purity 9950 — 248,750 mg fine — rather than example
     * 1's 250 g fine.
     *
     * It runs through the same FeeSchedule and TradeValueCalculator the engine
     * uses, so the arithmetic is the production arithmetic. It is asserted
     * separately rather than as an order because 248,750 mg is not a multiple
     * of GOLD-995-T0's 1,000 mg lot size: an order for it would be refused by
     * the instrument's own trading parameters (§4.1), which is correct.
     */
    #[Test]
    public function the_formula_appendix_example_prices_identically(): void
    {
        $fees = $this->app->make(FeeSchedule::class);

        $valuation = $this->app->make(TradeValueCalculator::class)->value(
            fineWeight: FineWeight::fromMilligrams(248_750),
            price: PricePerFineGram::fromRial(self::SELLER_PRICE),
            buyerFee: $fees->takerTerms(),
            sellerFee: $fees->makerTerms(),
            tax: $fees->taxTerms(),
        );

        $this->assertSame(19_521_900_000, $valuation->grossAmount->amount);
        $this->assertSame(29_282_850, $valuation->buyerFee->amount);
        $this->assertSame(19_521_900, $valuation->sellerFee->amount);
        $this->assertSame(19_551_182_850, $valuation->buyerNet->amount);
        $this->assertSame(19_502_378_100, $valuation->sellerNet->amount);
    }
}
