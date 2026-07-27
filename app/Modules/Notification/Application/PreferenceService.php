<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Infrastructure\NotificationPreference;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;

/**
 * Per-user, per-category notification settings (§15.3).
 *
 * Reads are cached per request: a single dispatch to an organisation's whole
 * treasury desk asks for the same category preference once per user, and the
 * settlement path is not the place to spend queries on it.
 */
final class PreferenceService
{
    /** @var array<string, ChannelPreferences> */
    private array $cache = [];

    public function for(int $userId, Category $category): ChannelPreferences
    {
        $key = $userId.'|'.$category->value;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $row = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('category', $category->value)
            ->first();

        $preferences = $row === null
            ? new ChannelPreferences($userId, $category)
            : new ChannelPreferences(
                userId: $userId,
                category: $category,
                inApp: $row->in_app,
                push: $row->push,
                sms: $row->sms,
                email: $row->email,
                quietHoursFrom: $row->quiet_hours_from !== null ? (string) $row->quiet_hours_from : null,
                quietHoursTo: $row->quiet_hours_to !== null ? (string) $row->quiet_hours_to : null,
            );

        return $this->cache[$key] = $preferences;
    }

    /**
     * @param  array<string, bool|string|null>  $settings
     */
    public function set(int $userId, Category $category, array $settings): ChannelPreferences
    {
        $this->assertValidWindow($settings);

        $values = array_intersect_key($settings, array_flip([
            'in_app', 'push', 'sms', 'email', 'quiet_hours_from', 'quiet_hours_to',
        ]));

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'category' => $category->value],
            $values,
        );

        unset($this->cache[$userId.'|'.$category->value]);

        return $this->for($userId, $category);
    }

    /** Apply the same settings to every category, for the "mute everything" switch. */
    public function setAll(int $userId, array $settings): void
    {
        foreach (Category::cases() as $category) {
            $this->set($userId, $category, $settings);
        }
    }

    /** @return array<string, ChannelPreferences> */
    public function all(int $userId): array
    {
        $preferences = [];

        foreach (Category::cases() as $category) {
            $preferences[$category->value] = $this->for($userId, $category);
        }

        return $preferences;
    }

    public function isQuietHours(int $userId, Category $category, ?Carbon $moment = null): bool
    {
        return $this->for($userId, $category)->isQuietAt($moment ?? Carbon::now());
    }

    /** Only for tests and long-running workers that outlive a preference change. */
    public function flushCache(): void
    {
        $this->cache = [];
    }

    /** @param  array<string, bool|string|null>  $settings */
    private function assertValidWindow(array $settings): void
    {
        $from = $settings['quiet_hours_from'] ?? null;
        $to = $settings['quiet_hours_to'] ?? null;

        // Half a window is not a window: one side set and the other null would
        // silently disable quiet hours, which is the opposite of what the user
        // just asked for.
        if (($from === null) !== ($to === null)) {
            throw new OperationNotPermittedException(
                'Quiet hours need both a start and an end time.'
            );
        }
    }
}
