<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * The stat row of §1.9. Every figure is an integer in its smallest unit;
 * formatting for display happens in the view, never here.
 */
final readonly class PlatformStats
{
    public function __construct(
        public int $activeMembers,
        public int $newMembersToday,
        public int $volumeTodayFineMg,
        public int $volumeYesterdayFineMg,
        public int $tradesToday,
        public int $tradesYesterday,
        public int $feeIncomeTodayRial,
        public int $feeIncomeYesterdayRial,
    ) {}
}
