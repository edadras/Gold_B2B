<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure;

use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Contracts\TradeRecord;
use App\Modules\Shared\Support\IntMath;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * In-process history, used by the AML tests and by anyone replaying a scenario
 * through the rule engine (§12.9 parameter tuning).
 */
final class InMemoryTradeHistoryReader implements TradeHistoryReaderInterface
{
    /** @var list<TradeRecord> */
    private array $trades = [];

    /** @param list<TradeRecord> $trades */
    public function __construct(array $trades = [])
    {
        foreach ($trades as $trade) {
            $this->add($trade);
        }
    }

    public function add(TradeRecord $trade): self
    {
        $this->trades[] = $trade;

        return $this;
    }

    public function tradesFor(int $organizationId, CarbonInterface $since): array
    {
        return $this->sorted(array_filter(
            $this->trades,
            static fn (TradeRecord $t): bool => $t->involves($organizationId)
                && $t->executedAt->greaterThanOrEqualTo($since),
        ));
    }

    public function tradesBetween(int $organizationId, int $counterpartyOrgId, CarbonInterface $since): array
    {
        return $this->sorted(array_filter(
            $this->trades,
            static fn (TradeRecord $t): bool => $t->executedAt->greaterThanOrEqualTo($since)
                && $t->involves($organizationId)
                && $t->involves($counterpartyOrgId),
        ));
    }

    public function recentTrades(CarbonInterface $since): array
    {
        return $this->sorted(array_filter(
            $this->trades,
            static fn (TradeRecord $t): bool => $t->executedAt->greaterThanOrEqualTo($since),
        ));
    }

    public function dailyVolumeMg(int $organizationId, CarbonInterface $day): int
    {
        $date = CarbonImmutable::instance($day)->toDateString();

        $matching = array_filter(
            $this->trades,
            static fn (TradeRecord $t): bool => $t->involves($organizationId)
                && $t->executedAt->toDateString() === $date,
        );

        return IntMath::sum(array_map(static fn (TradeRecord $t): int => $t->fineWeightMg, $matching));
    }

    public function averageDailyVolumeMg(int $organizationId, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $since = CarbonImmutable::now()->subDays($days);
        $today = CarbonImmutable::now()->toDateString();

        $matching = array_filter(
            $this->trades,
            static fn (TradeRecord $t): bool => $t->involves($organizationId)
                && $t->executedAt->greaterThanOrEqualTo($since)
                && $t->executedAt->toDateString() !== $today,
        );

        $total = IntMath::sum(array_map(static fn (TradeRecord $t): int => $t->fineWeightMg, $matching));

        return intdiv($total, $days);
    }

    /**
     * @param  array<int, TradeRecord>  $trades
     * @return list<TradeRecord>
     */
    private function sorted(array $trades): array
    {
        $trades = array_values($trades);

        usort(
            $trades,
            static fn (TradeRecord $a, TradeRecord $b): int => $a->executedAt->getTimestamp() <=> $b->executedAt->getTimestamp(),
        );

        return $trades;
    }
}
