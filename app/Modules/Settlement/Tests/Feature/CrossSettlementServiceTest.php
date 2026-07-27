<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Settlement\Application\CrossSettlementService;
use App\Modules\Settlement\Domain\CrossSettlementStatus;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Events\CrossSettlementExecuted;
use App\Modules\Settlement\Infrastructure\Models\CrossSettlementModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Cross settlement — docs/03-domain/05-settlement.md §5.7.
 *
 * The document's own example is the first test, to the milligram. Member A owes
 * member B five billion rial; B owes A a hundred grams of gold; at 78,500,000
 * rial per fine gram the metal is worth 7.85 bn, so 63.694 g of it discharges
 * the cash debt and 36.306 g stays outstanding in A's favour.
 */
final class CrossSettlementServiceTest extends SettlementTestCase
{
    /** Owes the rial, is owed the gold. */
    private const DEBTOR = 184;

    /** Owes the gold, is owed the rial. */
    private const CREDITOR = 291;

    private const RATE = 78_500_000;

    private const RIAL_DEBT = 5_000_000_000;

    private const GOLD_DEBT_MG = 100_000;

    private SettlementModel $rialSide;

    private SettlementModel $goldSide;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(self::DEBTOR, self::CREDITOR);
        $this->depositRial(self::DEBTOR, 20_000_000_000);
        $this->depositRial(self::CREDITOR, 20_000_000_000);
        $this->depositGold(self::CREDITOR, 1_000_000);

        // Obligation one: the debtor owes 5 bn rial on a trade it bought.
        $this->rialSide = $this->openSettlement(
            tradeId: 9_001,
            sellerOrgId: self::CREDITOR,
            buyerOrgId: self::DEBTOR,
            fineMg: 1_000,
            grossRial: self::RIAL_DEBT,
        );

        // Obligation two, pointing the other way: 100 g of metal owed back.
        $this->goldSide = $this->openSettlement(
            tradeId: 9_002,
            sellerOrgId: self::CREDITOR,
            buyerOrgId: self::DEBTOR,
            fineMg: self::GOLD_DEBT_MG,
            grossRial: 1_000,
        );
    }

    // ── the worked case ──────────────────────────────────────────────────────

    #[Test]
    #[Group('ledger-invariants')]
    public function it_reproduces_the_worked_case_of_section_5_7(): void
    {
        $cross = $this->agreedCross();

        $executed = $this->service()->execute((int) $cross->id, 77);

        self::assertSame(CrossSettlementStatus::EXECUTED, $executed->status);

        // floor(5,000,000,000 × 1000 ÷ 78,500,000) = 63,694 mg — §5.7's 63.694 g.
        self::assertSame(63_694, $executed->gold_applied_mg);

        // What those 63,694 mg are worth: floor(63,694 × 78,500,000 ÷ 1000).
        self::assertSame(4_999_979_000, $executed->gold_value_rial);

        // The debt is closed in full; the 21,000 rial the flooring could not
        // express in whole milligrams is the rounding remainder.
        self::assertSame(self::RIAL_DEBT, $executed->rial_discharged_rial);
        self::assertSame(21_000, $executed->rounding_rial);
        self::assertSame(0, $executed->rial_remaining_rial);

        // 100,000 − 63,694 = 36,306 mg — §5.7's «باقیمانده: A بستانکار 36.306g».
        self::assertSame(36_306, $executed->gold_remaining_mg);
        self::assertSame(36_306, $this->goldSide->refresh()->locked_gold_mg);

        // The cash obligation is gone and its settlement says so.
        self::assertSame(0, $this->rialSide->refresh()->locked_cash_rial);
        self::assertSame(SettlementStatus::PAYMENT_CONFIRMED, $this->rialSide->refresh()->status);

        $this->assertLedgerConserved();
    }

    #[Test]
    public function it_announces_the_cross_with_the_rate_it_used(): void
    {
        Event::fake([CrossSettlementExecuted::class]);

        $cross = $this->agreedCross();
        $this->service()->execute((int) $cross->id);

        Event::assertDispatched(
            CrossSettlementExecuted::class,
            static fn (CrossSettlementExecuted $e): bool => $e->agreedRateRial === self::RATE
                && $e->goldAppliedMg === 63_694
                && $e->roundingRial === 21_000
                && $e->goldRemainingMg === 36_306,
        );
    }

    // ── the recorded rate ────────────────────────────────────────────────────

    #[Test]
    public function the_rate_recorded_at_agreement_is_the_one_executed(): void
    {
        $cross = $this->agreedCross();

        // The market moves — sharply. A second proposal at the new rate is
        // refused while the agreed one is still open, and the agreed rate on
        // the row is untouched by anything that happens afterwards.
        try {
            $this->service()->propose(
                (int) $this->rialSide->id,
                (int) $this->goldSide->id,
                PricePerFineGram::fromRial(90_000_000),
                self::CREDITOR,
            );
            self::fail('A second open cross on the same settlements should be refused');
        } catch (OperationNotPermittedException) {
            // expected
        }

        $executed = $this->service()->execute((int) $cross->id);

        self::assertSame(self::RATE, $executed->agreed_rate_rial);

        // At 90,000,000 the debt would have taken only 55,555 mg. It took the
        // 63,694 mg the agreed rate implies, so the number the two members
        // signed up to is the number that moved metal.
        self::assertSame(63_694, $executed->gold_applied_mg);
        self::assertNotSame(55_555, $executed->gold_applied_mg);
    }

    #[Test]
    public function the_service_has_no_way_to_read_a_live_price(): void
    {
        // The strongest form of "the recorded rate is used": there is no price
        // source in the constructor, so no code path could substitute one.
        $constructor = (new ReflectionClass(CrossSettlementService::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            self::assertStringNotContainsString('Price', (string) $parameter->getType());
            self::assertStringNotContainsString('Pricing', (string) $parameter->getType());
        }
    }

    // ── conservation ─────────────────────────────────────────────────────────

    #[Test]
    #[Group('ledger-invariants')]
    public function conservation_holds_including_the_rounding_remainder(): void
    {
        $goldBefore = $this->goldBalance(self::CREDITOR);
        $rialBefore = $this->rialBalance(self::DEBTOR);
        $creditorRialBefore = $this->rialBalance(self::CREDITOR);

        $cross = $this->agreedCross();
        $this->service()->execute((int) $cross->id);

        // The debtor's locked cash comes back: the debt was settled in metal.
        self::assertSame($rialBefore + self::RIAL_DEBT, $this->rialBalance(self::DEBTOR));

        // The creditor keeps the metal it no longer has to deliver...
        self::assertSame($goldBefore + 63_694, $this->goldBalance(self::CREDITOR));

        // ...and the platform makes up the 21,000 rial that whole milligrams
        // could not express, so the creditor's claim is fully answered.
        self::assertSame($creditorRialBefore + 21_000, $this->rialBalance(self::CREDITOR));
        self::assertSame(
            -21_000,
            $this->systemAccountBalance(SystemAccountCode::ROUNDING_DIFFERENCE, AssetType::RIAL),
        );

        // Which is precisely why the books still balance.
        $this->assertEveryGroupBalances();
        $this->assertLedgerConserved();
        self::assertSame(0, $this->systemTotal(AssetType::RIAL));
        self::assertSame(0, $this->systemTotal(AssetType::GOLD));

        // 5,000,000,000 discharged = 4,999,979,000 of metal + 21,000 of dust.
        self::assertSame(
            $cross->refresh()->rial_discharged_rial,
            $cross->gold_value_rial + $cross->rounding_rial,
        );
    }

    // ── consent ──────────────────────────────────────────────────────────────

    #[Test]
    public function execution_is_refused_until_both_parties_have_agreed(): void
    {
        $cross = $this->service()->propose(
            (int) $this->rialSide->id,
            (int) $this->goldSide->id,
            PricePerFineGram::fromRial(self::RATE),
            self::DEBTOR,
            11,
        );

        self::assertSame(CrossSettlementStatus::PROPOSED, $cross->status);
        self::assertNotNull($cross->debtor_agreed_at);
        self::assertNull($cross->creditor_agreed_at);

        try {
            $this->service()->execute((int) $cross->id);
            self::fail('A cross settlement with one signature should not execute');
        } catch (OperationNotPermittedException $e) {
            self::assertStringContainsString('both parties', $e->reason);
        }

        // Nothing moved.
        self::assertSame(self::RIAL_DEBT, $this->rialSide->refresh()->locked_cash_rial);
        self::assertSame(self::GOLD_DEBT_MG, $this->goldSide->refresh()->locked_gold_mg);

        $agreed = $this->service()->agree((int) $cross->id, self::CREDITOR, 22);

        self::assertSame(CrossSettlementStatus::AGREED, $agreed->status);
        self::assertSame(
            CrossSettlementStatus::EXECUTED,
            $this->service()->execute((int) $cross->id)->status,
        );
    }

    #[Test]
    public function a_stranger_may_neither_agree_nor_reject(): void
    {
        $cross = $this->service()->propose(
            (int) $this->rialSide->id,
            (int) $this->goldSide->id,
            PricePerFineGram::fromRial(self::RATE),
            self::DEBTOR,
        );

        $this->expectException(OperationNotPermittedException::class);

        $this->service()->agree((int) $cross->id, 999);
    }

    #[Test]
    public function a_rejected_cross_never_executes(): void
    {
        $cross = $this->agreedCross();

        $this->service()->reject((int) $cross->id, self::CREDITOR, 'Rate no longer acceptable');

        $this->expectException(OperationNotPermittedException::class);

        $this->service()->execute((int) $cross->id);
    }

    // ── the other direction ──────────────────────────────────────────────────

    #[Test]
    #[Group('ledger-invariants')]
    public function metal_worth_less_than_the_debt_leaves_the_rial_remainder_outstanding(): void
    {
        // 100 g at 40,000,000 is worth 4,000,000,000 against a 5 bn debt, so
        // all of the metal goes and a billion rial is still owed. Nothing is
        // floored away here — the discharged amount IS the floored valuation —
        // so there is no rounding leg at all.
        $cross = $this->agreedCross(40_000_000);

        $executed = $this->service()->execute((int) $cross->id);

        self::assertSame(self::GOLD_DEBT_MG, $executed->gold_applied_mg);
        self::assertSame(4_000_000_000, $executed->rial_discharged_rial);
        self::assertSame(0, $executed->rounding_rial);
        self::assertSame(0, $executed->gold_remaining_mg);
        self::assertSame(1_000_000_000, $executed->rial_remaining_rial);

        // The cash settlement still owes the balance and stays awaiting payment.
        self::assertSame(1_000_000_000, $this->rialSide->refresh()->locked_cash_rial);
        self::assertSame(SettlementStatus::PAYMENT_PENDING, $this->rialSide->refresh()->status);

        self::assertSame(
            0,
            $this->systemAccountBalance(SystemAccountCode::ROUNDING_DIFFERENCE, AssetType::RIAL),
        );

        $this->assertLedgerConserved();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function agreedCross(int $rate = self::RATE): CrossSettlementModel
    {
        $cross = $this->service()->propose(
            (int) $this->rialSide->id,
            (int) $this->goldSide->id,
            PricePerFineGram::fromRial($rate),
            self::DEBTOR,
            11,
            'Sharia board opinion 1404/07/12',
        );

        return $this->service()->agree((int) $cross->id, self::CREDITOR, 22);
    }

    private function service(): CrossSettlementService
    {
        return $this->app->make(CrossSettlementService::class);
    }
}
