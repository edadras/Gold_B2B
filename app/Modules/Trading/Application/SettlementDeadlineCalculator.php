<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Shared\Support\SettingsRepository;
use App\Modules\Trading\Domain\SettlementType;
use Carbon\CarbonImmutable;

/**
 * When a trade must be settled by.
 *
 * Same-day types get `settlement.default_deadline_hours` from now, capped at
 * the session close — worked example 1 executes at 09:15 and carries a 17:00
 * deadline, which is the close, not eight hours later. Forward types land on
 * the close of the n-th following day.
 */
final readonly class SettlementDeadlineCalculator
{
    private const DEFAULT_DEADLINE_HOURS = 8;

    public function __construct(private SettingsRepository $settings) {}

    public function deadlineFor(SettlementType $type, ?CarbonImmutable $executedAt = null): CarbonImmutable
    {
        $executedAt ??= CarbonImmutable::now();
        $days = $type->daysOffset();

        if ($days > 0) {
            return $this->closeOf($executedAt->addDays($days));
        }

        $byHours = $executedAt->addHours($this->deadlineHours());
        $close = $this->closeOf($executedAt);

        return $byHours->lessThan($close) ? $byHours : $close;
    }

    private function deadlineHours(): int
    {
        $stored = $this->settings->get('settlement.default_deadline_hours');

        if (is_int($stored)) {
            return $stored;
        }

        $configured = config('goldb2b.settlement.default_deadline_hours');

        return is_int($configured) ? $configured : self::DEFAULT_DEADLINE_HOURS;
    }

    private function closeOf(CarbonImmutable $day): CarbonImmutable
    {
        $stored = $this->settings->get('market.close_time');
        $time = is_string($stored) && $stored !== ''
            ? $stored
            : (string) (config('goldb2b.market.close_time') ?? '17:30');

        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '0');

        return $day->setTime((int) $hour, (int) $minute);
    }
}
