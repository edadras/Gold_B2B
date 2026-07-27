<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Domain;

/**
 * Verification tier (docs/03-domain/14-reputation.md §14.4).
 *
 * The criteria table in the doc is count-based; the thresholds below add the
 * three anti-gaming dimensions of §14.8 that a pure trade count cannot express:
 *
 *  - a minimum *volume*, so a thousand token trades cannot buy a badge that a
 *    real book earns;
 *  - a minimum number of *distinct counterparties*, which is the one metric two
 *    colluding members cannot inflate by trading with each other;
 *  - the maker-share requirement the doc already gives PLATINUM.
 *
 * Tiers are cumulative: GOLD means "everything SILVER asks for, plus more".
 * `requirements()` returns one tier's own criteria, `cumulativeRequirements()`
 * the full set that `qualifies()` actually evaluates.
 */
enum VerificationTier: string
{
    case BRONZE = 'BRONZE';
    case SILVER = 'SILVER';
    case GOLD = 'GOLD';
    case PLATINUM = 'PLATINUM';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** Ordered lowest to highest, so tiers can be compared and iterated. */
    public function rank(): int
    {
        return match ($this) {
            self::BRONZE => 1,
            self::SILVER => 2,
            self::GOLD => 3,
            self::PLATINUM => 4,
        };
    }

    public function isHigherThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    public function badge(): string
    {
        return match ($this) {
            self::BRONZE => '🥉',
            self::SILVER => '🥈',
            self::GOLD => '🥇',
            self::PLATINUM => '💎',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BRONZE => 'برنزی',
            self::SILVER => 'نقره‌ای',
            self::GOLD => 'طلایی',
            self::PLATINUM => 'ممتاز',
        };
    }

    /**
     * This tier's own criteria. Rates are basis points and are compared
     * strictly (the doc says "> 95%", not "≥ 95%"); everything else is a
     * minimum, or a maximum where the key says `max_`.
     *
     * @return array<string, int|bool>
     */
    public function requirements(): array
    {
        return match ($this) {
            self::BRONZE => [
                'kyc_basic_verified' => true,
                'min_trades' => 1,
            ],
            self::SILVER => [
                'kyc_full_verified' => true,
                'bank_account_verified' => true,
                'min_trades' => 50,
                'min_membership_days' => 90,
                'min_on_time_rate_bps' => 9_500,
                // Anti-gaming (§14.8): 1 kg of real business, spread over at
                // least five houses.
                'min_volume_mg' => 1_000_000,
                'min_distinct_counterparties' => 5,
            ],
            self::GOLD => [
                'min_trades' => 500,
                'min_membership_days' => 365,
                'min_on_time_rate_bps' => 9_900,
                'max_dispute_rate_bps' => 10,
                'max_defaults' => 0,
                'min_volume_mg' => 50_000_000,
                'min_distinct_counterparties' => 20,
            ],
            self::PLATINUM => [
                'min_trades' => 5_000,
                'min_maker_share_bps' => 5_000,
                'min_volume_mg' => 500_000_000,
                'min_distinct_counterparties' => 50,
            ],
        };
    }

    /**
     * This tier's criteria merged with every lower tier's.
     *
     * Later (higher) tiers win on a shared key, which is why the merge walks
     * upwards: GOLD's 99% on-time replaces SILVER's 95%.
     *
     * @return array<string, int|bool>
     */
    public function cumulativeRequirements(): array
    {
        $merged = [];

        foreach (self::cases() as $tier) {
            if ($tier->rank() > $this->rank()) {
                continue;
            }

            $merged = array_merge($merged, $tier->requirements());
        }

        return $merged;
    }

    /** Human-readable criteria for the "how do I reach the next tier" panel. */
    public function describe(): string
    {
        return match ($this) {
            self::BRONZE => 'احراز هویت پایه و حداقل یک معامله',
            self::SILVER => 'احراز هویت کامل، حساب بانکی تأییدشده، ۵۰ معامله، ۹۰ روز عضویت، تسویه به‌موقع بالای ۹۵٪ و حداقل ۵ طرف‌حساب متمایز',
            self::GOLD => 'همه شرایط نقره‌ای، ۵۰۰ معامله، یک سال عضویت، تسویه به‌موقع بالای ۹۹٪، نرخ اختلاف زیر ۰.۱٪، بدون نکول و حداقل ۲۰ طرف‌حساب متمایز',
            self::PLATINUM => 'همه شرایط طلایی، ۵۰۰۰ معامله، بازارسازی بیش از ۵۰٪ حجم و حداقل ۵۰ طرف‌حساب متمایز',
        };
    }

    public function qualifies(ReputationSnapshot $snapshot): bool
    {
        return $this->unmetRequirements($snapshot) === [];
    }

    /**
     * Which criteria this snapshot fails, so a member can be told what is
     * missing instead of being left to guess (§14.9 — the right to understand
     * where a number comes from).
     *
     * @return list<string>
     */
    public function unmetRequirements(ReputationSnapshot $snapshot): array
    {
        $unmet = [];

        foreach ($this->cumulativeRequirements() as $key => $expected) {
            $met = match ($key) {
                'kyc_basic_verified' => $snapshot->kycBasicVerified,
                'kyc_full_verified' => $snapshot->kycFullVerified,
                'bank_account_verified' => $snapshot->bankAccountVerified,
                'min_trades' => $snapshot->totalTrades >= $expected,
                'min_membership_days' => $snapshot->membershipDays() >= $expected,
                'min_volume_mg' => $snapshot->totalVolumeMg >= $expected,
                'min_distinct_counterparties' => $snapshot->distinctCounterparties >= $expected,
                // Rates are strict comparisons, per the doc's "> 95%".
                'min_on_time_rate_bps' => $snapshot->onTimeRateBps() > $expected,
                'max_dispute_rate_bps' => $snapshot->disputeRateBps() < $expected,
                'min_maker_share_bps' => $snapshot->makerShareBps() > $expected,
                'max_defaults' => $snapshot->settlementsDefaulted <= $expected,
                default => true,
            };

            if ($met !== true) {
                $unmet[] = $key;
            }
        }

        return $unmet;
    }

    /** The highest tier this snapshot currently satisfies. */
    public static function highestQualifying(ReputationSnapshot $snapshot): ?self
    {
        $best = null;

        foreach (self::cases() as $tier) {
            if ($tier->qualifies($snapshot)) {
                $best = $tier;
            }
        }

        return $best;
    }
}
