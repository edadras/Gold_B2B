<?php

declare(strict_types=1);

namespace App\Modules\Risk\Jobs;

use App\Modules\Risk\Infrastructure\Models\DailyCounter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors one Redis increment into daily_counters. Idempotency is not required:
 * the row is an additive aggregate and the Redis hash remains the authority for
 * the pre-trade check.
 */
final class PersistDailyCounter implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $counterDate,
        public readonly int $volumeMg,
        public readonly int $valueRial,
        public readonly int $tradeCount = 1,
    ) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            /** @var DailyCounter $counter */
            $counter = DailyCounter::query()
                ->firstOrCreate(
                    [
                        'organization_id' => $this->organizationId,
                        'counter_date' => $this->counterDate,
                    ],
                    ['volume_mg' => 0, 'value_rial' => 0, 'trade_count' => 0],
                );

            DailyCounter::query()
                ->whereKey($counter->getKey())
                ->update([
                    'volume_mg' => DB::raw('volume_mg + '.$this->volumeMg),
                    'value_rial' => DB::raw('value_rial + '.$this->valueRial),
                    'trade_count' => DB::raw('trade_count + '.$this->tradeCount),
                    'updated_at' => now(),
                ]);
        });
    }
}
