<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Contracts\DeliveryOutcome;
use App\Modules\Notification\Contracts\DispatchResult;
use App\Modules\Notification\Contracts\Notifier;
use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Contracts\OutboundMessage;
use App\Modules\Notification\Contracts\Recipient;
use App\Modules\Notification\Contracts\RecipientDirectory;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Domain\RoleTargeting;
use App\Modules\Notification\Infrastructure\Notification;
use App\Modules\Notification\Infrastructure\NotificationDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The six dispatch rules of docs/03-domain/15-notification-reporting.md §15.4,
 * in one place.
 *
 *   1. CRITICAL always sends, overriding preferences and quiet hours.
 *   2. Quiet hours apply to LOW and NORMAL only.
 *   3. More than five same-code notifications inside five minutes collapse
 *      into one aggregate.
 *   4. One event produces at most one notification per user, keyed on
 *      (code, subject_id, user_id).
 *   5. Role targeting decides who hears about what.
 *   6. SMS is reserved for critical, financial and security codes.
 *
 * They live here rather than in the drivers on purpose: a rule that is spread
 * across four channel implementations is a rule that will eventually be true in
 * three of them. The drivers know nothing except how to reach one destination.
 *
 * Nothing here runs inside a database transaction that spans a send
 * (AGENT_BRIEF rule 3): each notification row is written and committed, then
 * the external channels are attempted, so a slow provider can never hold a
 * financial transaction's locks.
 */
final class NotificationDispatcher implements Notifier
{
    public function __construct(
        private readonly RecipientDirectory $directory,
        private readonly PreferenceService $preferences,
        private readonly ChannelRegistry $channels,
    ) {}

    public function dispatch(NotificationSpec $spec): DispatchResult
    {
        $now = Carbon::now();
        $recipients = $this->resolveRecipients($spec);

        $notificationIds = [];
        $skipped = [];
        $aggregated = [];
        $channelCounts = [];

        foreach ($recipients as $recipient) {
            // Rule 4 — deduplication.
            if ($this->alreadySent($spec, $recipient, $now)) {
                $skipped[] = $recipient->id;

                continue;
            }

            // Rule 3 — batching.
            if ($this->shouldAggregate($spec, $recipient, $now)) {
                $this->aggregate($spec, $recipient, $now);
                $aggregated[] = $recipient->id;

                continue;
            }

            $message = $this->message($spec, $recipient);

            // In-app is written for every notification, whatever the hour and
            // whatever the user muted: §15.1 gives the feed everything, and a
            // muted channel means "do not buzz my phone", not "hide this".
            $inApp = $this->channels->for(Channel::IN_APP)->send($message);

            if ($inApp->notificationId === null) {
                continue;
            }

            $notificationIds[] = $inApp->notificationId;
            $message = $message->withNotificationId($inApp->notificationId);

            foreach ($this->channelsFor($spec->code, $recipient, $now) as $channel) {
                foreach ($this->destinations($channel, $recipient) as $destination) {
                    $this->deliver($channel, $message, $destination);
                    $channelCounts[$channel->value] = ($channelCounts[$channel->value] ?? 0) + 1;
                }
            }
        }

        return new DispatchResult(
            notificationIds: $notificationIds,
            skippedUserIds: $skipped,
            aggregatedUserIds: $aggregated,
            targetedUserIds: array_map(static fn (Recipient $r): int => $r->id, $recipients),
            channelCounts: $channelCounts,
        );
    }

    /**
     * Rule 5 — role-based targeting.
     *
     * An explicit user list wins (the settlement counterparty who must confirm
     * a payment is a person, not a role); otherwise the catalogue's role map
     * decides. VIEWER holds no role in that map, so a read-only account is
     * never paged.
     *
     * @return list<Recipient>
     */
    public function resolveRecipients(NotificationSpec $spec): array
    {
        if ($spec->userIds !== null) {
            $recipients = [];

            foreach ($spec->userIds as $userId) {
                $recipient = $this->directory->find($userId);

                if ($recipient !== null) {
                    $recipients[] = $recipient;
                }
            }

            return $recipients;
        }

        return $this->directory->usersWithRoles(
            $spec->organizationId,
            RoleTargeting::roleNamesFor($spec->code),
        );
    }

    /**
     * Rules 1, 2 and 6, applied in that order.
     *
     * @return list<Channel>
     */
    public function channelsFor(NotificationCode $code, Recipient $recipient, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        $channels = array_values(array_filter(
            $code->defaultChannels(),
            static fn (Channel $channel): bool => $channel->isExternal(),
        ));

        // Rule 1 — CRITICAL ignores preferences and quiet hours entirely, and
        // §15.5 puts it on push and SMS whatever the catalogue's default said.
        if ($code->priority()->overridesPreferences()) {
            $channels = array_merge($channels, [Channel::PUSH, Channel::SMS]);

            return $this->applySmsRule($code, $this->unique($channels));
        }

        $preferences = $this->preferences->for($recipient->id, $code->category());

        // Rule 2 — quiet hours silence LOW and NORMAL only. HIGH still goes
        // out: a settlement deadline two hours away is worth waking up for.
        if ($code->priority()->respectsQuietHours() && $preferences->isQuietAt($now)) {
            return [];
        }

        $channels = array_filter(
            $channels,
            static fn (Channel $channel): bool => $preferences->allows($channel),
        );

        return $this->applySmsRule($code, $this->unique($channels));
    }

    /**
     * Rule 6 — SMS costs money on every message and is the channel members
     * actually read, so the catalogue decides which codes may use it. A user
     * who switched SMS *on* for a chatty category still does not get one.
     *
     * @param  list<Channel>  $channels
     * @return list<Channel>
     */
    private function applySmsRule(NotificationCode $code, array $channels): array
    {
        if ($code->allowsSms()) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            static fn (Channel $channel): bool => $channel !== Channel::SMS,
        ));
    }

    /**
     * Rule 4 — the same event must not notify the same user twice.
     *
     * Only meaningful when the caller identified the subject: without a subject
     * id there is nothing to compare, and two "your order was filled" events for
     * two different orders are not duplicates.
     *
     * The window exists because the alternative — suppressing forever — would
     * make a legitimate reminder about the same settlement the next day
     * impossible.
     */
    public function alreadySent(NotificationSpec $spec, Recipient $recipient, ?Carbon $now = null): bool
    {
        if ($spec->subjectId === null) {
            return false;
        }

        $now = $now ?? Carbon::now();
        $hours = (int) config('goldb2b.notification.dedup_window_hours', 24);

        return Notification::query()
            ->where('user_id', $recipient->id)
            ->where('code', $spec->code->value)
            ->where('subject_id', $spec->subjectId)
            ->where('created_at', '>=', $now->copy()->subHours($hours))
            ->exists();
    }

    /**
     * Rule 3 — batching.
     *
     * Once the same code has produced `batch_threshold` individual rows for one
     * user inside the window, further ones collapse: forty "order filled" lines
     * are noise, "۴۰ سفارش شما اجرا شد" is information.
     */
    public function shouldAggregate(NotificationSpec $spec, Recipient $recipient, ?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now();

        // A CRITICAL notification is never collapsed: each one demands its own
        // action, and burying the second default of the day inside a counter
        // defeats the point of the priority.
        if ($spec->priority()->overridesPreferences()) {
            return false;
        }

        return $this->windowCount($spec, $recipient, $now) >= $this->batchThreshold();
    }

    /**
     * Either bump the live aggregate row or open one. The count is the total
     * number of same-code notifications in the window, so the aggregate reads
     * as a replacement for the individual lines rather than an addition to them.
     */
    private function aggregate(NotificationSpec $spec, Recipient $recipient, Carbon $now): void
    {
        $windowStart = $now->copy()->subMinutes($this->batchWindowMinutes());

        $existing = Notification::query()
            ->where('user_id', $recipient->id)
            ->where('code', $spec->code->value)
            ->whereNotNull('aggregate_count')
            ->where('created_at', '>=', $windowStart)
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            $count = $existing->aggregate_count + 1;

            DB::table('notifications')
                ->where('id', $existing->id)
                ->update([
                    'aggregate_count' => $count,
                    'title' => $spec->code->aggregateTitle($count),
                    'body' => $spec->code->aggregateBody($count),
                    // Bubble it back to the top of the feed: the aggregate is
                    // about what is happening now, not when it started.
                    'created_at' => $now->toDateTimeString(),
                ]);

            return;
        }

        $count = $this->windowCount($spec, $recipient, $now) + 1;

        $notification = new Notification;
        $notification->fill([
            'organization_id' => $spec->organizationId,
            'user_id' => $recipient->id,
            'code' => $spec->code->value,
            'category' => $spec->category()->value,
            'priority' => $spec->priority()->value,
            'title' => $spec->code->aggregateTitle($count),
            'body' => $spec->code->aggregateBody($count),
            'action_type' => $spec->actionType,
            // No subject: an aggregate is about many subjects, and pinning it to
            // the last one would send the reader to an arbitrary record.
            'aggregate_count' => $count,
            'aggregate_window_start' => $windowStart->toDateTimeString(),
        ]);
        $notification->save();
    }

    private function windowCount(NotificationSpec $spec, Recipient $recipient, Carbon $now): int
    {
        return Notification::query()
            ->where('user_id', $recipient->id)
            ->where('code', $spec->code->value)
            ->whereNull('aggregate_count')
            ->where('created_at', '>=', $now->copy()->subMinutes($this->batchWindowMinutes()))
            ->count();
    }

    /**
     * One delivery attempt: the row is written first so a driver that throws
     * still leaves evidence that we tried.
     */
    private function deliver(Channel $channel, OutboundMessage $message, ?string $destination): void
    {
        if ($destination === null) {
            $this->recordDelivery(
                $channel,
                $message,
                OutboundMessage::hashDestination($channel, 'none'),
                DeliveryOutcome::skipped('no destination registered for this channel'),
                attempts: 0,
            );

            return;
        }

        $hashed = OutboundMessage::hashDestination($channel, $destination);
        $outbound = $message->withDestination($hashed);

        try {
            $outcome = $this->channels->for($channel)->send($outbound);
        } catch (\Throwable $e) {
            $outcome = DeliveryOutcome::failed(mb_substr($e->getMessage(), 0, 500));
        }

        $this->recordDelivery($channel, $message, $hashed, $outcome, attempts: 1);
    }

    private function recordDelivery(
        Channel $channel,
        OutboundMessage $message,
        string $destination,
        DeliveryOutcome $outcome,
        int $attempts,
    ): void {
        $delivery = new NotificationDelivery;
        $delivery->fill([
            'notification_id' => $message->notificationId,
            'channel' => $channel->value,
            'destination' => $destination,
            'status' => $outcome->status,
            'provider_ref' => $outcome->providerRef,
            'attempts' => $attempts,
            'error' => $outcome->error,
            'sent_at' => $outcome->succeeded() ? Carbon::now()->toDateTimeString() : null,
        ]);
        $delivery->save();
    }

    /**
     * Where a channel reaches this recipient. Push fans out over every
     * registered device — there is no way to know which one is in their hand.
     *
     * @return list<string|null> a single null means "no destination"
     */
    private function destinations(Channel $channel, Recipient $recipient): array
    {
        return match ($channel) {
            Channel::PUSH => $recipient->pushTokens !== [] ? $recipient->pushTokens : [null],
            Channel::SMS => [$recipient->mobile],
            Channel::EMAIL => [$recipient->email],
            Channel::WEBHOOK => ['webhook:org:'.$recipient->organizationId],
            Channel::IN_APP => [null],
        };
    }

    private function message(NotificationSpec $spec, Recipient $recipient): OutboundMessage
    {
        return new OutboundMessage(
            organizationId: $spec->organizationId,
            userId: $recipient->id,
            code: $spec->code->value,
            category: $spec->category()->value,
            priority: $spec->priority()->value,
            title: $spec->title(),
            body: $spec->body(),
            destination: '',
            actionType: $spec->actionType,
            actionPayload: $spec->actionPayload,
            subjectType: $spec->subjectType,
            subjectId: $spec->subjectId,
        );
    }

    /**
     * @param  array<int, Channel>  $channels
     * @return list<Channel>
     */
    private function unique(array $channels): array
    {
        $seen = [];

        foreach ($channels as $channel) {
            $seen[$channel->value] = $channel;
        }

        return array_values($seen);
    }

    private function batchThreshold(): int
    {
        return max(1, (int) config('goldb2b.notification.batch_threshold', 5));
    }

    private function batchWindowMinutes(): int
    {
        return max(1, (int) config('goldb2b.notification.batch_window_minutes', 5));
    }
}
