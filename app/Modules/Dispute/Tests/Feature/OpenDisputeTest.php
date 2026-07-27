<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests\Feature;

use App\Modules\Dispute\Application\DisputeService;
use App\Modules\Dispute\Contracts\OpenDisputeCommand;
use App\Modules\Dispute\Contracts\TradeParties;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\DisputeType;
use App\Modules\Dispute\Events\DisputeOpened;
use App\Modules\Dispute\Tests\DisputeTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * §13.4 — «مبلغ قفل‌شده = فقط مبلغ مورد ادعا، نه کل معامله».
 */
final class OpenDisputeTest extends DisputeTestCase
{
    private const CLAIMANT = 184;

    private const RESPONDENT = 209;

    private DisputeService $disputes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disputes = $this->app->make(DisputeService::class);
    }

    #[Test]
    public function only_the_disputed_amount_is_locked_not_the_whole_trade(): void
    {
        // The §13.4 example: a 500 g fine trade booked at عیار ۹۹۵, worth
        // 39,240,000,000 rial in total, disputed at عیار ۹۸۵.
        $trade = $this->documentedTrade();

        $dispute = $this->disputes->open($this->purityClaim());

        // ── The figures the document arrives at ────────────────────────────
        self::assertSame(5_025, $dispute->claim_gold_mg, 'shortfall in fine milligrams');
        self::assertSame(394_362_000, $dispute->claim_rial, 'rial equivalent of the shortfall');

        // ── And what was actually locked ───────────────────────────────────
        self::assertSame(5_025, $this->holds->amountOf('holdGold'));
        self::assertSame(394_362_000, $this->holds->amountOf('holdRial'));

        // The whole trade must never appear in a hold.
        foreach ($this->holds->calls as $call) {
            self::assertNotSame($trade->fineMg, $call['amount'], 'the full weight was locked');
            self::assertNotSame($trade->grossRial, $call['amount'], 'the full trade value was locked');
        }

        // The hold falls on the respondent — the party said to owe something.
        self::assertSame(self::RESPONDENT, $this->holds->callsTo('holdGold')[0]['org']);
        self::assertSame(self::RESPONDENT, $this->holds->callsTo('holdRial')[0]['org']);

        // 394 million against a 39 billion trade — almost exactly the 1% of
        // purity that is actually in question, and nothing else.
        self::assertLessThan(
            intdiv($trade->grossRial, 50),
            $dispute->claim_rial,
            'the locked amount should be a small fraction of the trade',
        );
    }

    #[Test]
    public function the_hold_entry_ids_are_recorded_against_the_case(): void
    {
        $this->documentedTrade();

        $dispute = $this->disputes->open($this->purityClaim());

        self::assertNotNull($dispute->hold_gold_entry_id);
        self::assertNotNull($dispute->hold_rial_entry_id);
        self::assertFalse($dispute->hold_released);
    }

    #[Test]
    public function opening_starts_the_twenty_four_hour_reply_clock(): void
    {
        $this->documentedTrade();

        $dispute = $this->disputes->open($this->purityClaim());

        self::assertSame(DisputeStatus::AWAITING_REPLY->value, $dispute->status);
        self::assertNotNull($dispute->reply_deadline_at);

        $hours = $dispute->opened_at->diffInHours($dispute->reply_deadline_at);

        self::assertEqualsWithDelta(24, $hours, 1);
        self::assertNull($dispute->negotiation_deadline_at, 'the negotiation clock has not started');
    }

    #[Test]
    public function a_party_cannot_open_a_dispute_on_a_trade_they_were_not_part_of(): void
    {
        // The trade is between 184 and 209; 999 has nothing to do with it.
        $this->documentedTrade();

        $this->expectException(OperationNotPermittedException::class);
        $this->expectExceptionMessageMatches('/OPERATION_NOT_PERMITTED/');

        $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::PURITY_MISMATCH,
            claimantOrgId: 999,
            openedByUserId: 41,
            claimDescription: 'عیار تحویلی ۹۸۵ است نه ۹۹۵',
            tradeId: 88231,
            actualPurityX10k: 9_850,
        ));
    }

    #[Test]
    public function an_outsider_who_tries_locks_nothing_and_files_nothing(): void
    {
        $this->documentedTrade();

        try {
            $this->disputes->open(new OpenDisputeCommand(
                type: DisputeType::PURITY_MISMATCH,
                claimantOrgId: 999,
                openedByUserId: 41,
                claimDescription: 'ادعای شخص ثالث',
                tradeId: 88231,
                actualPurityX10k: 9_850,
            ));
            self::fail('an outsider must not be able to open a dispute');
        } catch (OperationNotPermittedException) {
            // expected
        }

        self::assertSame([], $this->holds->calls, 'nothing may be locked on a refused claim');
        self::assertSame(0, DB::table('disputes')->count());
    }

    #[Test]
    public function a_dispute_against_an_unverifiable_trade_is_refused(): void
    {
        // No trade registered: the provider answers null, which must read as
        // "no", not as "go ahead".
        $this->expectException(OperationNotPermittedException::class);

        $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::PURITY_MISMATCH,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 41,
            claimDescription: 'ادعا روی معامله‌ای که سامانه نمی‌شناسد',
            tradeId: 404_404,
            actualPurityX10k: 9_850,
        ));
    }

    #[Test]
    public function either_side_of_the_trade_may_be_the_claimant(): void
    {
        $this->documentedTrade();

        // The seller complaining about the buyer, rather than the other way round.
        $dispute = $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::PAYMENT_NOT_RECEIVED,
            claimantOrgId: self::RESPONDENT,
            openedByUserId: 77,
            claimDescription: 'وجه معامله دریافت نشد',
            tradeId: 88231,
            claimRial: 39_240_000_000,
        ));

        self::assertSame(self::RESPONDENT, $dispute->claimant_org_id);
        self::assertSame(self::CLAIMANT, $dispute->respondent_org_id);
    }

    #[Test]
    public function a_weight_claim_locks_the_plain_difference(): void
    {
        $this->trades->add(new TradeParties(
            tradeId: 5150,
            buyerOrgId: self::CLAIMANT,
            sellerOrgId: self::RESPONDENT,
            fineMg: 250_000,
            purityX10k: 9_950,
            pricePerFineGram: 78_480_000,
            grossRial: 19_620_000_000,
        ));

        $dispute = $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::WEIGHT_MISMATCH,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 41,
            claimDescription: 'وزن تحویلی کمتر از ثبت‌شده است',
            tradeId: 5150,
            actualFineMg: 248_000,
        ));

        self::assertSame(2_000, $dispute->claim_gold_mg);
        self::assertSame(156_960_000, $dispute->claim_rial);
    }

    #[Test]
    public function a_claim_with_no_derivable_figure_locks_what_the_claimant_states(): void
    {
        $dispute = $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::PAYMENT_NOT_MADE,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 41,
            claimDescription: 'خریدار پرداخت نکرده است',
            respondentOrgId: self::RESPONDENT,
            claimRial: 1_500_000_000,
        ));

        self::assertSame(0, $dispute->claim_gold_mg);
        self::assertSame(1_500_000_000, $dispute->claim_rial);
        self::assertSame(1_500_000_000, $this->holds->amountOf('holdRial'));
        self::assertSame(0, $this->holds->countOf('holdGold'));
    }

    #[Test]
    public function a_claim_with_nothing_quantified_still_opens_but_locks_nothing(): void
    {
        $dispute = $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::DELIVERY_NOT_MADE,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 41,
            claimDescription: 'طلا تحویل نشد',
            respondentOrgId: self::RESPONDENT,
        ));

        self::assertSame(0, $dispute->claim_gold_mg);
        self::assertSame(0, $dispute->claim_rial);
        self::assertSame([], $this->holds->calls);
        self::assertSame(DisputeStatus::AWAITING_REPLY->value, $dispute->status);
    }

    #[Test]
    public function opening_writes_the_timeline_the_case_screen_renders(): void
    {
        $this->documentedTrade();

        $dispute = $this->disputes->open($this->purityClaim());

        $actions = DB::table('dispute_timeline')
            ->where('dispute_id', $dispute->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        // §13.9: the case is opened, the funds are locked, the clock starts.
        self::assertSame(['DISPUTE_OPENED', 'FUNDS_HELD', 'AUTO_AWAIT_REPLY'], $actions);

        $held = DB::table('dispute_timeline')
            ->where('dispute_id', $dispute->id)
            ->where('action', 'FUNDS_HELD')
            ->value('message');

        self::assertStringContainsString('394,362,000', (string) $held);
    }

    #[Test]
    public function case_numbers_follow_the_documented_form(): void
    {
        $this->documentedTrade();
        $this->documentedTrade(tradeId: 88232);

        $first = $this->disputes->open($this->purityClaim());
        $second = $this->disputes->open($this->purityClaim(tradeId: 88232));

        self::assertMatchesRegularExpression('/^DSP-\d{4}-\d{5}$/', $first->case_number);
        self::assertNotSame($first->case_number, $second->case_number);
    }

    #[Test]
    public function opening_announces_itself_after_the_case_exists(): void
    {
        Event::fake([DisputeOpened::class]);

        $this->documentedTrade();

        $dispute = $this->disputes->open($this->purityClaim());

        Event::assertDispatched(
            DisputeOpened::class,
            static function (DisputeOpened $event) use ($dispute): bool {
                return $event->disputeId === (int) $dispute->id
                    && $event->claimGoldMg === 5_025
                    && $event->claimRial === 394_362_000
                    && $event->fundsHeld === true;
            },
        );
    }

    #[Test]
    public function an_organisation_cannot_dispute_with_itself(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OpenDisputeCommand(
            type: DisputeType::AMOUNT_MISMATCH,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 1,
            claimDescription: 'خودم با خودم',
            respondentOrgId: self::CLAIMANT,
        );
    }

    private function purityClaim(int $tradeId = 88231): OpenDisputeCommand
    {
        return new OpenDisputeCommand(
            type: DisputeType::PURITY_MISMATCH,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 41,
            claimDescription: 'عیار تحویلی ۹۸۵ است نه ۹۹۵. گواهی پیوست است.',
            tradeId: $tradeId,
            actualPurityX10k: 9_850,
        );
    }
}
