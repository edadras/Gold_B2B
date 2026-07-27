<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\AlertCondition;
use App\Modules\Pricing\Domain\AlertStatus;
use App\Modules\Pricing\Events\PriceAlertTriggered;
use App\Modules\Pricing\Infrastructure\Models\PriceAlert;
use App\Modules\Pricing\Infrastructure\Models\PriceCandle;
use App\Modules\Shared\Support\IntMath;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Evaluated on every market_quotes update, throttled so a jittering price does
 * not produce a stream of notifications (docs/03-domain/07-pricing.md §7.9).
 */
final class PriceAlertService
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly CacheRepository $cache,
    ) {}

    /** @return list<int> ids of the alerts that fired */
    public function evaluate(int $instrumentId, int $currentPriceRial): array
    {
        /** @var list<PriceAlert> $alerts */
        $alerts = PriceAlert::query()
            ->active()
            ->where('instrument_id', $instrumentId)
            ->orderBy('id')
            ->get()
            ->all();

        $fired = [];

        foreach ($alerts as $alert) {
            if (! $this->matches($alert, $currentPriceRial)) {
                continue;
            }

            if ($this->isThrottled($alert)) {
                continue;
            }

            DB::transaction(function () use ($alert): void {
                $alert->forceFill([
                    'status' => $alert->is_recurring
                        ? AlertStatus::ACTIVE->value
                        : AlertStatus::TRIGGERED->value,
                    'triggered_at' => CarbonImmutable::now(),
                ])->save();
            });

            $this->markThrottled($alert);
            $fired[] = (int) $alert->id;

            $this->events->dispatch(new PriceAlertTriggered(
                alertId: (int) $alert->id,
                organizationId: (int) $alert->organization_id,
                userId: (int) $alert->user_id,
                instrumentId: $instrumentId,
                condition: $alert->condition->value,
                threshold: $alert->threshold,
                observedRial: $currentPriceRial,
            ));
        }

        return $fired;
    }

    private function matches(PriceAlert $alert, int $currentPriceRial): bool
    {
        return match ($alert->condition) {
            AlertCondition::ABOVE => $currentPriceRial > $alert->threshold,
            AlertCondition::BELOW => $currentPriceRial < $alert->threshold,
            AlertCondition::CHANGE_PERCENT => $this->changeExceeds($alert, $currentPriceRial),
        };
    }

    /**
     * "Tell me when the price moves more than 2% in an hour" — compared against
     * the opening price of the earliest 1m candle inside the window.
     */
    private function changeExceeds(PriceAlert $alert, int $currentPriceRial): bool
    {
        $since = CarbonImmutable::now()->subSeconds($alert->window_seconds);

        /** @var PriceCandle|null $baseline */
        $baseline = PriceCandle::query()
            ->where('instrument_id', $alert->instrument_id)
            ->where('interval_code', '1m')
            ->where('opened_at', '>=', $since)
            ->orderBy('opened_at')
            ->first();

        if ($baseline === null || $baseline->open_price === 0) {
            return false;
        }

        $changeBps = IntMath::mulDivFloor(
            abs($currentPriceRial - $baseline->open_price),
            10_000,
            $baseline->open_price,
        );

        return $changeBps >= abs($alert->threshold);
    }

    private function throttleKey(PriceAlert $alert): string
    {
        return "pricing:alert:throttle:{$alert->id}";
    }

    private function isThrottled(PriceAlert $alert): bool
    {
        return $this->cache->has($this->throttleKey($alert));
    }

    private function markThrottled(PriceAlert $alert): void
    {
        $seconds = (int) config('goldb2b.pricing.alert_throttle_seconds', 300);

        $this->cache->put($this->throttleKey($alert), true, $seconds);
    }
}
