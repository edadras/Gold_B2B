<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** §14.6 — accumulating statistics that concurrent settlements cannot lose. */
final class StatsUpdaterTest extends ReputationTestCase
{
    #[Test]
    public function settlements_accumulate_on_both_sides_of_the_trade(): void
    {
        $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 23);
        $this->stats->recordSettlement(22, 250_000, onTime: true, settlementMinutes: 23);
        $this->stats->recordSettlement(11, 100_000, onTime: false, settlementMinutes: 400);

        $row = DB::table('reputation_stats')->where('organization_id', 11)->first();

        self::assertSame(2, (int) $row->total_trades);
        self::assertSame(350_000, (int) $row->total_volume_mg);
        self::assertSame(2, (int) $row->settlements_total);
        self::assertSame(1, (int) $row->settlements_on_time);
        self::assertSame(1, (int) $row->settlements_late);
        self::assertSame(423, (int) $row->total_settlement_minutes);
        self::assertNotNull($row->last_active_at);
    }

    #[Test]
    public function the_statistics_write_is_a_single_accumulating_statement(): void
    {
        DB::enableQueryLog();

        $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 5);

        $statsWrites = array_values(array_filter(
            array_map(
                // Collapse the SQL's formatting whitespace so the assertions can
                // talk about the statement rather than its indentation.
                static fn (array $q): string => (string) preg_replace('/\s+/', ' ', strtolower((string) $q['query'])),
                DB::getQueryLog(),
            ),
            static fn (string $q): bool => str_contains($q, 'reputation_stats'),
        ));

        DB::disableQueryLog();

        self::assertCount(1, $statsWrites);
        self::assertStringContainsString('on duplicate key update', $statsWrites[0]);
        self::assertStringContainsString('total_trades = total_trades + 1', $statsWrites[0]);
        self::assertStringNotContainsString('select', $statsWrites[0]);
    }

    #[Test]
    public function a_trade_below_the_minimum_size_moves_nothing_but_the_activity_clock(): void
    {
        $minimum = $this->stats->minCountableTradeMg();

        $counted = $this->stats->recordSettlement(11, $minimum - 1, onTime: true, settlementMinutes: 1);

        self::assertFalse($counted);

        $row = DB::table('reputation_stats')->where('organization_id', 11)->first();

        self::assertSame(0, (int) $row->total_trades);
        self::assertSame(0, (int) $row->total_volume_mg);
        self::assertSame(0, (int) $row->settlements_total);
        self::assertNotNull($row->last_active_at, 'the member was still active');

        // Exactly at the threshold it counts.
        self::assertTrue($this->stats->recordSettlement(11, $minimum, onTime: true, settlementMinutes: 1));
        self::assertSame(1, (int) DB::table('reputation_stats')->where('organization_id', 11)->value('total_trades'));
    }

    #[Test]
    public function maker_and_taker_volume_are_tracked_separately(): void
    {
        $this->stats->recordSettlement(11, 300_000, onTime: true, settlementMinutes: 5, isMaker: true);
        $this->stats->recordSettlement(11, 100_000, onTime: true, settlementMinutes: 5, isMaker: false);

        $row = DB::table('reputation_stats')->where('organization_id', 11)->first();

        self::assertSame(300_000, (int) $row->maker_volume_mg);
        self::assertSame(100_000, (int) $row->taker_volume_mg);
    }

    #[Test]
    public function defaults_and_disputes_are_counted(): void
    {
        $this->stats->recordDefault(11);
        $this->stats->recordDispute(11, lost: true);
        $this->stats->recordDispute(11, lost: false);

        $row = DB::table('reputation_stats')->where('organization_id', 11)->first();

        self::assertSame(1, (int) $row->settlements_defaulted);
        self::assertSame(2, (int) $row->disputes_involved);
        self::assertSame(1, (int) $row->disputes_lost);
    }

    #[Test]
    public function rfq_and_quote_activity_is_counted(): void
    {
        $this->stats->recordRfqReceived(11);
        $this->stats->recordRfqReceived(11);
        $this->stats->recordRfqResponse(11, 4);
        $this->stats->recordQuoteAccepted(11);
        $this->stats->recordQuoteFilled(11);

        $row = DB::table('reputation_stats')->where('organization_id', 11)->first();

        self::assertSame(2, (int) $row->rfq_received);
        self::assertSame(1, (int) $row->rfq_responded);
        self::assertSame(4, (int) $row->rfq_total_response_minutes);
        self::assertSame(1, (int) $row->quotes_accepted);
        self::assertSame(1, (int) $row->quotes_filled);
    }

    #[Test]
    public function member_since_survives_later_activity(): void
    {
        $joined = Carbon::now()->subDays(500);
        $this->stats->ensure(11, $joined);

        $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 5);

        $stored = Carbon::parse(
            (string) DB::table('reputation_stats')->where('organization_id', 11)->value('member_since')
        );

        self::assertSame($joined->toDateString(), $stored->toDateString());
    }

    #[Test]
    public function day_periods_are_rolled_up_as_events_arrive(): void
    {
        $today = Carbon::parse('2026-03-05 10:00:00');

        $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 5, occurredAt: $today);
        $this->stats->recordSettlement(11, 150_000, onTime: false, settlementMinutes: 900, occurredAt: $today);

        $period = DB::table('reputation_periods')
            ->where('organization_id', 11)
            ->where('period_type', 'DAY')
            ->where('period_start', '2026-03-05')
            ->first();

        self::assertNotNull($period);
        self::assertSame(2, (int) $period->trades);
        self::assertSame(400_000, (int) $period->volume_mg);
        self::assertSame(5_000, (int) $period->on_time_rate_bps);
    }

    #[Test]
    public function verification_flags_are_booleans_and_nothing_else(): void
    {
        $this->stats->setVerification(11, kycBasicVerified: true);
        $this->stats->setVerification(11, kycFullVerified: true, bankAccountVerified: true);

        $row = DB::table('reputation_stats')->where('organization_id', 11)->first();

        self::assertSame(1, (int) $row->kyc_basic_verified);
        self::assertSame(1, (int) $row->kyc_full_verified);
        self::assertSame(1, (int) $row->bank_account_verified);
    }
}
