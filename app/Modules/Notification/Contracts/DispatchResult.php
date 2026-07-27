<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

/**
 * What a dispatch actually did.
 *
 * Returned rather than logged, because the six rules of §15.4 are the sort of
 * thing that needs to be assertable: "nothing was sent" and "nothing was sent
 * because it was a duplicate" are different outcomes, and a caller (or a test)
 * has to be able to tell them apart.
 */
final readonly class DispatchResult
{
    /**
     * @param  list<int>  $notificationIds
     * @param  list<int>  $skippedUserIds  suppressed by deduplication
     * @param  list<int>  $aggregatedUserIds  collapsed into an aggregate row
     * @param  list<int>  $targetedUserIds  everyone role targeting selected
     * @param  array<string, int>  $channelCounts  channel value => deliveries recorded
     */
    public function __construct(
        public array $notificationIds = [],
        public array $skippedUserIds = [],
        public array $aggregatedUserIds = [],
        public array $targetedUserIds = [],
        public array $channelCounts = [],
    ) {}

    public function created(): int
    {
        return count($this->notificationIds);
    }

    public function deliveriesOn(string $channel): int
    {
        return $this->channelCounts[$channel] ?? 0;
    }

    public function usedChannel(string $channel): bool
    {
        return $this->deliveriesOn($channel) > 0;
    }
}
