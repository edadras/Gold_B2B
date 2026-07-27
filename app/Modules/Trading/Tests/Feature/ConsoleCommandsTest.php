<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Commands\CreateOtcOfferCommand;
use App\Modules\Trading\Application\OtcService;
use App\Modules\Trading\Database\Seeders\InstrumentsSeeder;
use App\Modules\Trading\Domain\MarketSessionStatus;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\OtcOfferStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OtcOffer;
use App\Modules\Trading\Tests\TradingTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The five artisan entry points the scheduler drives. They are thin wrappers,
 * so what is tested is the wiring — that each command is registered, resolves
 * its service and reaches the right state — not the business logic underneath,
 * which has its own tests.
 */
final class ConsoleCommandsTest extends TradingTestCase
{
    use RefreshDatabase;

    private const PRICE = 78_480_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG);
        $this->depositGold(self::SELLER_ORG, 5_000_000);
        $this->depositRial(self::BUYER_ORG, 500_000_000_000);
    }

    #[Test]
    public function the_instruments_seeder_creates_the_three_documented_instruments_once(): void
    {
        // setUpMarket already ran it; running it again must not duplicate.
        $this->artisan('db:seed', ['--class' => InstrumentsSeeder::class])
            ->assertSuccessful();

        $codes = Instrument::query()->orderBy('code')->pluck('code')->all();

        $this->assertSame(['GOLD-750-T0', 'GOLD-995-T0', 'GOLD-995-T1'], $codes);

        $t0 = Instrument::query()->where('code', 'GOLD-995-T0')->firstOrFail();
        $this->assertSame(9950, $t0->min_purity_x10);
        $this->assertSame(10_000, $t0->tick_size_rial);
        $this->assertSame(1_000, $t0->lot_size_mg);
        $this->assertSame(50_000, $t0->min_order_mg);
        $this->assertSame(50_000_000, $t0->max_order_mg);

        // The 750 instrument asks for a larger minimum parcel.
        $this->assertSame(
            100_000,
            Instrument::query()->where('code', 'GOLD-750-T0')->firstOrFail()->min_order_mg,
        );
    }

    #[Test]
    public function market_open_takes_a_scheduled_session_to_pre_open_then_open(): void
    {
        $this->sessions()->close($this->instrument()->id);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());

        try {
            $this->artisan('market:open', ['instrument' => ['GOLD-995-T0'], '--pre-open' => true])
                ->assertSuccessful();

            $this->assertSame(
                MarketSessionStatus::PRE_OPEN,
                $this->sessions()->currentSession($this->instrument()->id)?->status,
            );

            $this->artisan('market:open', ['instrument' => ['GOLD-995-T0']])->assertSuccessful();

            $this->assertSame(
                MarketSessionStatus::OPEN,
                $this->sessions()->currentSession($this->instrument()->id)?->status,
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function market_halt_pauses_and_market_close_ends_the_session(): void
    {
        $this->artisan('market:halt', ['--reason' => 'exchange incident'])->assertSuccessful();

        $session = $this->sessions()->currentSession($this->instrument()->id);
        $this->assertSame(MarketSessionStatus::PAUSED, $session?->status);
        $this->assertSame('exchange incident', $session?->pause_reason);

        $this->artisan('market:close', ['--closing-price' => (string) self::PRICE])->assertSuccessful();

        $session = $this->sessions()->currentSession($this->instrument()->id);
        $this->assertSame(MarketSessionStatus::CLOSED, $session?->status);
        $this->assertSame(self::PRICE, $session?->closing_price_rial);
    }

    #[Test]
    public function orders_expire_retires_a_gtd_order_past_its_deadline(): void
    {
        $order = $this->placeLimit(
            self::SELLER_ORG,
            self::SELLER_USER,
            Side::SELL,
            100_000,
            self::PRICE,
            TimeInForce::GTD,
        )->order;

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(3));

        try {
            $this->artisan('orders:expire')->assertSuccessful();

            $this->assertSame(OrderStatus::EXPIRED, Order::query()->findOrFail($order->id)->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function rfq_expire_stale_also_sweeps_otc_offers(): void
    {
        $offer = $this->app->make(OtcService::class)->createOffer(new CreateOtcOfferCommand(
            organizationId: self::SELLER_ORG,
            counterpartyOrganizationId: self::BUYER_ORG,
            userId: self::SELLER_USER,
            instrumentCode: self::INSTRUMENT,
            side: Side::SELL,
            quantity: FineWeight::fromMilligrams(100_000),
            price: PricePerFineGram::fromRial(self::PRICE),
            expiresAt: CarbonImmutable::now()->addMinutes(30),
        ));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));

        try {
            $this->artisan('rfq:expire-stale')->assertSuccessful();

            $this->assertSame(
                OtcOfferStatus::EXPIRED,
                OtcOffer::query()->findOrFail($offer->id)->status,
            );
            $this->assertSame(5_000_000, $this->goldBalance(self::SELLER_ORG));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
