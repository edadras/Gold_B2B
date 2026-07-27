<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Dispute\Contracts\TradePartiesProvider;
use App\Modules\Settlement\Contracts\TradeReaderInterface;
use App\Modules\Trading\Database\Seeders\InstrumentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two modules read Trading through a contract of their own, and both contracts
 * were bound to a Null implementation in the running application.
 *
 * Dispute's was the one that mattered. `TradePartiesProvider` answers "was this
 * member party to that trade?", DisputeService correctly treats "I don't know"
 * as a refusal, and the Null implementation says "I don't know" about every
 * trade there has ever been. So no trade-linked dispute could be opened in
 * production — and nearly every dispute this platform models is about a trade.
 * The Dispute suite could not see it: it installs a stub provider of its own,
 * which is right for testing the module and blind to how it is wired.
 *
 * Settlement's `TradeReaderInterface` was quieter. Settlement works from the
 * TradeExecuted event, so the primary path never needed it; the secondary path
 * — opening a settlement for a trade whose event was lost — asked whether the
 * trade was settleable, was told no about everything, and did nothing.
 *
 * These tests use the real container bindings and a real trade row. That is the
 * whole point: a test that resolved the adapter directly would pass just as
 * happily with the Null one still bound.
 */
final class TradeLookupWiringTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER = 184;

    private const SELLER = 209;

    #[Test]
    public function dispute_can_identify_the_parties_to_a_trade(): void
    {
        $tradeId = $this->insertTrade();

        $parties = $this->app->make(TradePartiesProvider::class)->forTrade($tradeId);

        $this->assertNotNull($parties, 'A real trade must be found — a null here closes the whole module');
        $this->assertSame(self::BUYER, $parties->buyerOrgId);
        $this->assertSame(self::SELLER, $parties->sellerOrgId);
        $this->assertSame(250_000, $parties->fineMg);
        $this->assertSame(19_620_000_000, $parties->grossRial);

        // Read from the instrument, not from the trade: fineness is a property
        // of what was traded, and a عیار claim is computed against it.
        $this->assertSame(9_950, $parties->purityX10k);
    }

    #[Test]
    public function both_sides_are_recognised_and_a_stranger_is_not(): void
    {
        $tradeId = $this->insertTrade();

        $parties = $this->app->make(TradePartiesProvider::class)->forTrade($tradeId);

        $this->assertTrue($parties?->includes(self::BUYER));
        $this->assertTrue($parties?->includes(self::SELLER));
        $this->assertFalse($parties?->includes(999_999), 'A stranger must not be able to open a claim');

        $this->assertSame(self::SELLER, $parties?->counterpartyOf(self::BUYER));
        $this->assertSame(self::BUYER, $parties?->counterpartyOf(self::SELLER));
    }

    #[Test]
    public function an_unknown_trade_is_still_refused(): void
    {
        // The safe direction has to survive the fix: a trade that does not
        // exist must read as null, not as an empty-but-present answer that
        // `includes()` would evaluate against zeroes.
        $this->assertNull($this->app->make(TradePartiesProvider::class)->forTrade(999_999));
    }

    #[Test]
    public function settlement_can_read_a_trade_it_never_saw_the_event_for(): void
    {
        $tradeId = $this->insertTrade();

        $snapshot = $this->app->make(TradeReaderInterface::class)->find($tradeId);

        $this->assertNotNull($snapshot);
        $this->assertSame(250_000, $snapshot->quantityFineMg);
        $this->assertSame(19_620_000_000, $snapshot->grossAmountRial);

        // F9 — what each side actually parts with and receives.
        $this->assertSame(19_620_000_000 + 29_430_000, $snapshot->buyerNetRial());
        $this->assertSame(19_620_000_000 - 19_620_000, $snapshot->sellerNetRial());
    }

    #[Test]
    public function an_executed_trade_is_settleable_and_a_settled_one_is_not(): void
    {
        $reader = $this->app->make(TradeReaderInterface::class);

        $open = $this->insertTrade();
        $this->assertTrue($reader->isSettleable($open));

        // Already carries a settlement: a second one would lock the obligation
        // twice and each would demand its own payment.
        DB::table('trades')->where('id', $open)->update(['settlement_id' => 42]);
        $this->assertFalse($reader->isSettleable($open));

        $reversed = $this->insertTrade('TRD-REV-1');
        DB::table('trades')->where('id', $reversed)->update(['status' => 'REVERSED']);
        $this->assertFalse($reader->isSettleable($reversed), 'A human has taken charge of a reversed trade');
    }

    /** A trade row exactly as Trading writes one. */
    private function insertTrade(string $code = 'TRD-1400-0001'): int
    {
        $this->seed(InstrumentsSeeder::class);

        $instrumentId = (int) DB::table('instruments')->where('code', 'GOLD-995-T0')->value('id');

        return (int) DB::table('trades')->insertGetId([
            'trade_code' => $code,
            'instrument_id' => $instrumentId,
            'trade_source' => 'ORDER_BOOK',
            'buyer_organization_id' => self::BUYER,
            'seller_organization_id' => self::SELLER,
            'maker_side' => 'SELL',
            'quantity_fine_mg' => 250_000,
            'price_per_gram_rial' => 78_480_000,
            'gross_amount_rial' => 19_620_000_000,
            'buyer_fee_rial' => 29_430_000,
            'seller_fee_rial' => 19_620_000,
            'tax_rial' => 0,
            'buyer_net_rial' => 19_620_000_000 + 29_430_000,
            'seller_net_rial' => 19_620_000_000 - 19_620_000,
            'settlement_type' => 'T0',
            'delivery_type' => 'VAULT_TRANSFER',
            'settlement_deadline' => now()->addDay(),
            'status' => 'EXECUTED',
            'executed_at' => now(),
        ]);
    }
}
