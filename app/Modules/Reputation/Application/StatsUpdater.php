<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Application;

use App\Modules\Reputation\Domain\VerificationTier;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the cumulative statistics of §14.5 and the DAY roll-ups of §14.6.
 *
 * Every write is an accumulating upsert. Settlement completions arrive
 * concurrently for both sides of every trade in the market, and a
 * read-modify-write would quietly drop some of them — a statistic that
 * undercounts is worse than no statistic, because members act on it.
 *
 * Anti-gaming (§14.8 attack 1): a trade below `min_countable_trade_mg` moves no
 * counter except `last_active_at`. Volume is accumulated alongside the count so
 * the tier rules can weight by *how much* was traded rather than how often,
 * which is the difference between a reputation and a click counter.
 */
final class StatsUpdater
{
    public function minCountableTradeMg(): int
    {
        $value = config('goldb2b.reputation.min_countable_trade_mg', 10_000);

        return is_numeric($value) ? (int) $value : 10_000;
    }

    public function countsTowardStats(int $fineWeightMg): bool
    {
        return abs($fineWeightMg) >= $this->minCountableTradeMg();
    }

    /** Create the row if the member has none yet, without disturbing an existing one. */
    public function ensure(int $organizationId, DateTimeInterface|string|null $memberSince = null): void
    {
        $since = $memberSince !== null ? Carbon::parse($memberSince) : Carbon::now();

        DB::statement(
            'INSERT INTO reputation_stats (organization_id, verification_tier, member_since, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE organization_id = organization_id',
            [
                $organizationId,
                VerificationTier::BRONZE->value,
                $since->toDateTimeString(),
                Carbon::now()->toDateTimeString(),
            ],
        );
    }

    /**
     * A completed settlement, from the point of view of one of its two sides.
     *
     * @return bool whether the trade was large enough to count
     */
    public function recordSettlement(
        int $organizationId,
        int $fineWeightMg,
        bool $onTime,
        int $settlementMinutes,
        bool $isMaker = false,
        DateTimeInterface|string|null $occurredAt = null,
    ): bool {
        $at = $occurredAt !== null ? Carbon::parse($occurredAt) : Carbon::now();
        $volume = abs($fineWeightMg);

        if (! $this->countsTowardStats($volume)) {
            // Below the threshold: the member was active, but nothing about this
            // trade may influence a badge.
            $this->touch($organizationId, $at);

            return false;
        }

        $now = Carbon::now()->toDateTimeString();

        DB::statement(
            'INSERT INTO reputation_stats
                (organization_id, total_trades, total_volume_mg,
                 settlements_total, settlements_on_time, settlements_late,
                 total_settlement_minutes, maker_volume_mg, taker_volume_mg,
                 verification_tier, member_since, last_active_at, updated_at)
             VALUES (?, 1, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_trades             = total_trades + 1,
                total_volume_mg          = total_volume_mg + VALUES(total_volume_mg),
                settlements_total        = settlements_total + 1,
                settlements_on_time      = settlements_on_time + VALUES(settlements_on_time),
                settlements_late         = settlements_late + VALUES(settlements_late),
                total_settlement_minutes = total_settlement_minutes + VALUES(total_settlement_minutes),
                maker_volume_mg          = maker_volume_mg + VALUES(maker_volume_mg),
                taker_volume_mg          = taker_volume_mg + VALUES(taker_volume_mg),
                last_active_at           = VALUES(last_active_at),
                updated_at               = VALUES(updated_at)',
            [
                $organizationId,
                $volume,
                $onTime ? 1 : 0,
                $onTime ? 0 : 1,
                max(0, $settlementMinutes),
                $isMaker ? $volume : 0,
                $isMaker ? 0 : $volume,
                VerificationTier::BRONZE->value,
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $now,
            ],
        );

        $this->recordDayPeriod(
            $organizationId,
            $at,
            trades: 1,
            volumeMg: $volume,
            settlements: 1,
            onTime: $onTime ? 1 : 0,
            disputes: 0,
        );

        return true;
    }

    /** A settlement that was never completed (§14.4: GOLD requires zero defaults). */
    public function recordDefault(int $organizationId, DateTimeInterface|string|null $occurredAt = null): void
    {
        $at = $occurredAt !== null ? Carbon::parse($occurredAt) : Carbon::now();

        $this->increment($organizationId, [
            'settlements_total' => 1,
            'settlements_defaulted' => 1,
        ], $at);
    }

    /**
     * A dispute this member was party to. `lost` is what §14.3 counts in the
     * dispute rate: merely being disputed against is not evidence of anything,
     * and counting it would let a bad actor damage a rival by filing claims.
     */
    public function recordDispute(
        int $organizationId,
        bool $lost = false,
        bool $frivolous = false,
        DateTimeInterface|string|null $occurredAt = null,
    ): void {
        $at = $occurredAt !== null ? Carbon::parse($occurredAt) : Carbon::now();

        $this->increment($organizationId, [
            'disputes_involved' => 1,
            'disputes_lost' => $lost ? 1 : 0,
            'disputes_frivolous' => $frivolous ? 1 : 0,
        ], $at);

        $this->recordDayPeriod($organizationId, $at, 0, 0, 0, 0, disputes: 1);
    }

    /** §14.8 attack 3: declining to quote is itself visible. */
    public function recordRfqReceived(int $organizationId): void
    {
        $this->increment($organizationId, ['rfq_received' => 1]);
    }

    public function recordRfqResponse(int $organizationId, int $responseMinutes): void
    {
        $this->increment($organizationId, [
            'rfq_responded' => 1,
            'rfq_total_response_minutes' => max(0, $responseMinutes),
        ]);
    }

    public function recordQuoteAccepted(int $organizationId): void
    {
        $this->increment($organizationId, ['quotes_accepted' => 1]);
    }

    public function recordQuoteFilled(int $organizationId): void
    {
        $this->increment($organizationId, ['quotes_filled' => 1]);
    }

    /**
     * Verification flags fed by Identity/Kyc events. Booleans only — no KYC
     * content ever reaches this module (§14.2).
     */
    public function setVerification(
        int $organizationId,
        ?bool $kycBasicVerified = null,
        ?bool $kycFullVerified = null,
        ?bool $bankAccountVerified = null,
    ): void {
        $this->ensure($organizationId);

        $changes = array_filter([
            'kyc_basic_verified' => $kycBasicVerified,
            'kyc_full_verified' => $kycFullVerified,
            'bank_account_verified' => $bankAccountVerified,
        ], static fn (?bool $v): bool => $v !== null);

        if ($changes === []) {
            return;
        }

        $changes['updated_at'] = Carbon::now()->toDateTimeString();

        DB::table('reputation_stats')
            ->where('organization_id', $organizationId)
            ->update($changes);
    }

    public function touch(int $organizationId, DateTimeInterface|string|null $at = null): void
    {
        $when = $at !== null ? Carbon::parse($at) : Carbon::now();
        $now = Carbon::now()->toDateTimeString();

        DB::statement(
            'INSERT INTO reputation_stats
                (organization_id, verification_tier, member_since, last_active_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_active_at = VALUES(last_active_at),
                updated_at     = VALUES(updated_at)',
            [
                $organizationId,
                VerificationTier::BRONZE->value,
                $when->toDateTimeString(),
                $when->toDateTimeString(),
                $now,
            ],
        );
    }

    /**
     * Generic accumulating increment over a whitelist of counter columns.
     *
     * @param  array<string, int>  $counters
     */
    private function increment(int $organizationId, array $counters, ?Carbon $at = null): void
    {
        $allowed = [
            'total_trades', 'settlements_total', 'settlements_on_time', 'settlements_late',
            'settlements_defaulted', 'disputes_involved', 'disputes_lost', 'disputes_frivolous',
            'rfq_received', 'rfq_responded', 'rfq_total_response_minutes',
            'quotes_accepted', 'quotes_filled',
        ];

        $columns = array_intersect_key($counters, array_flip($allowed));

        if ($columns === []) {
            return;
        }

        $when = ($at ?? Carbon::now())->toDateTimeString();
        $now = Carbon::now()->toDateTimeString();

        $names = array_keys($columns);
        $insertColumns = implode(', ', $names);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $updates = implode(', ', array_map(
            static fn (string $c): string => "{$c} = {$c} + VALUES({$c})",
            $names,
        ));

        DB::statement(
            "INSERT INTO reputation_stats
                (organization_id, {$insertColumns}, verification_tier, member_since, last_active_at, updated_at)
             VALUES (?, {$placeholders}, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                {$updates},
                last_active_at = VALUES(last_active_at),
                updated_at     = VALUES(updated_at)",
            array_merge(
                [$organizationId],
                array_values($columns),
                [VerificationTier::BRONZE->value, $when, $when, $now],
            ),
        );
    }

    /** DAY roll-up, upserted the same accumulating way. */
    public function recordDayPeriod(
        int $organizationId,
        Carbon $at,
        int $trades,
        int $volumeMg,
        int $settlements,
        int $onTime,
        int $disputes,
    ): void {
        DB::statement(
            'INSERT INTO reputation_periods
                (organization_id, period_type, period_start, trades, volume_mg,
                 settlements_total, settlements_on_time, disputes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                trades              = trades + VALUES(trades),
                volume_mg           = volume_mg + VALUES(volume_mg),
                settlements_total   = settlements_total + VALUES(settlements_total),
                settlements_on_time = settlements_on_time + VALUES(settlements_on_time),
                disputes            = disputes + VALUES(disputes),
                -- Assignments in ON DUPLICATE KEY UPDATE are evaluated left to
                -- right, so the two settlement columns already hold their new
                -- totals here; adding VALUES() again would count the row twice.
                on_time_rate_bps    = CASE
                    WHEN settlements_total = 0 THEN NULL
                    ELSE FLOOR(settlements_on_time * 10000 / settlements_total)
                END',
            [
                $organizationId,
                'DAY',
                $at->toDateString(),
                $trades,
                $volumeMg,
                $settlements,
                $onTime,
                $disputes,
            ],
        );

        // The insert path cannot compute the rate in the VALUES list, so it is
        // set once here for a freshly created row.
        if ($settlements > 0) {
            DB::statement(
                'UPDATE reputation_periods
                 SET on_time_rate_bps = FLOOR(settlements_on_time * 10000 / settlements_total)
                 WHERE organization_id = ? AND period_type = ? AND period_start = ?
                   AND on_time_rate_bps IS NULL AND settlements_total > 0',
                [$organizationId, 'DAY', $at->toDateString()],
            );
        }
    }
}
