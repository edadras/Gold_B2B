<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Shared\Calculation\FeeTerms;
use App\Modules\Shared\Calculation\TaxTerms;
use App\Modules\Shared\Support\SettingsRepository;

/**
 * Where the maker and taker rates come from.
 *
 * AGENT_BRIEF rule 6: no magic numbers. Operators change fees at runtime, so
 * system_settings wins; config/goldb2b.php supplies the bootstrap default when
 * the table has no row yet (fresh install, or a unit test with no database).
 *
 * The maker discount is the economic counterpart of the maker-price execution
 * rule of §4.4: whoever provided the liquidity pays less and is filled at their
 * own price.
 */
final readonly class FeeSchedule
{
    private const SETTING_TAKER = 'fee.default_taker_x100k';

    private const SETTING_MAKER = 'fee.default_maker_x100k';

    public function __construct(private SettingsRepository $settings) {}

    /** The party that removed liquidity — 0.15% by default. */
    public function takerRateX100k(): int
    {
        return $this->rate(self::SETTING_TAKER, 'fees.default_taker_x100k', 150);
    }

    /** The party that provided it — 0.10% by default. */
    public function makerRateX100k(): int
    {
        return $this->rate(self::SETTING_MAKER, 'fees.default_maker_x100k', 100);
    }

    public function takerTerms(): FeeTerms
    {
        return FeeTerms::rate($this->takerRateX100k());
    }

    public function makerTerms(): FeeTerms
    {
        return FeeTerms::rate($this->makerRateX100k());
    }

    /** Terms for one side, given whether that side was the maker. */
    public function termsFor(bool $isMaker): FeeTerms
    {
        return $isMaker ? $this->makerTerms() : $this->takerTerms();
    }

    /**
     * Zero for inter-dealer melted gold under the current regulatory position
     * (docs/10-compliance/01-regulatory.md §1.1(ز)); kept behind a method so the
     * day it changes there is one place to change it.
     */
    public function taxTerms(): TaxTerms
    {
        return TaxTerms::none();
    }

    private function rate(string $settingKey, string $configKey, int $fallback): int
    {
        $stored = $this->settings->get($settingKey);

        if (is_int($stored)) {
            return $stored;
        }

        if (is_string($stored) && preg_match('/^\d+$/', $stored) === 1) {
            return (int) $stored;
        }

        $configured = config('goldb2b.'.$configKey);

        return is_int($configured) ? $configured : $fallback;
    }
}
