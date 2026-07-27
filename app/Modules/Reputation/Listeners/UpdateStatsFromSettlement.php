<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Listeners;

use App\Modules\Reputation\Application\StatsUpdater;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Turns settlement outcomes into public statistics (§14.6).
 *
 * Settlement is not a dependency of this module, so its events are subscribed
 * to by string name and read defensively. Both sides of a settlement are
 * credited: on-time completion is a joint achievement, and a member cannot
 * settle on time if its counterparty does not.
 */
final class UpdateStatsFromSettlement
{
    private const COMPLETED = 'App\Modules\Settlement\Events\SettlementCompleted';

    private const DEFAULTED = 'App\Modules\Settlement\Events\SettlementDefaulted';

    public function __construct(private readonly StatsUpdater $stats) {}

    public function handle(object $event): void
    {
        $class = $event::class;

        // When the real classes exist, only they are accepted.
        if (class_exists(self::COMPLETED) && is_a($event, self::COMPLETED)) {
            $this->completed($event);

            return;
        }

        if (class_exists(self::DEFAULTED) && is_a($event, self::DEFAULTED)) {
            $this->defaulted($event);

            return;
        }

        if ($class === self::COMPLETED) {
            $this->completed($event);
        } elseif ($class === self::DEFAULTED) {
            $this->defaulted($event);
        }
    }

    private function completed(object $event): void
    {
        $organizationIds = $this->parties($event);

        if ($organizationIds === [] || ! property_exists($event, 'fineWeightMg')) {
            return;
        }

        $onTime = $this->wasOnTime($event);
        $minutes = $this->minutes($event);
        $makerOrgId = property_exists($event, 'makerOrgId') ? (int) $event->makerOrgId : null;

        foreach ($organizationIds as $organizationId) {
            $this->stats->recordSettlement(
                organizationId: $organizationId,
                fineWeightMg: (int) $event->fineWeightMg,
                onTime: $onTime,
                settlementMinutes: $minutes,
                isMaker: $makerOrgId !== null && $makerOrgId === $organizationId,
                occurredAt: property_exists($event, 'completedAt') ? $this->toString($event->completedAt) : null,
            );
        }
    }

    private function defaulted(object $event): void
    {
        foreach ($this->parties($event) as $organizationId) {
            $this->stats->recordDefault($organizationId);
        }
    }

    /** @return list<int> */
    private function parties(object $event): array
    {
        foreach ([['buyerOrgId', 'sellerOrgId'], ['goldDelivererOrgId', 'goldReceiverOrgId']] as [$a, $b]) {
            if (property_exists($event, $a) && property_exists($event, $b)) {
                $left = (int) $event->{$a};
                $right = (int) $event->{$b};

                return $left > 0 && $right > 0 && $left !== $right ? [$left, $right] : [];
            }
        }

        return [];
    }

    private function wasOnTime(object $event): bool
    {
        if (property_exists($event, 'wasOnTime')) {
            return (bool) $event->wasOnTime;
        }

        if (! property_exists($event, 'completedAt') || ! property_exists($event, 'deadlineAt')) {
            // No deadline in the payload: assume the settlement was fine rather
            // than penalise a member for a missing field.
            return true;
        }

        return Carbon::parse($this->toString($event->completedAt))
            ->lessThanOrEqualTo(Carbon::parse($this->toString($event->deadlineAt)));
    }

    private function minutes(object $event): int
    {
        if (property_exists($event, 'settlementMinutes')) {
            return (int) $event->settlementMinutes;
        }

        if (! property_exists($event, 'executedAt') || ! property_exists($event, 'completedAt')) {
            return 0;
        }

        return (int) Carbon::parse($this->toString($event->executedAt))
            ->diffInMinutes(Carbon::parse($this->toString($event->completedAt)));
    }

    private function toString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
