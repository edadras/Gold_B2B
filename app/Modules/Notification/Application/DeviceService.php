<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Infrastructure\PushDevice;
use Illuminate\Support\Carbon;

/**
 * Registering and retiring the push targets a member's phones are known by.
 *
 * The module had a `push_devices` table and a reader for it (used when a push
 * is dispatched) but nothing that let a device put itself there, so the
 * `/devices` endpoints of §2.14 had no service to call. This is it.
 *
 * Like the feed, devices are USER-scoped: a token belongs to a person's handset
 * and an OWNER has no business revoking a colleague's phone.
 */
final class DeviceService
{
    /**
     * Register — or re-register — a push token.
     *
     * Idempotent on the token, which is what makes it safe for the client to
     * call on every cold start: FCM and APNs hand the same app instance the
     * same token until they rotate it, and a second POST must refresh the row
     * rather than accumulate duplicates. `push_devices.token` is uniquely
     * indexed, so this is enforced by the schema and not merely by convention.
     *
     * A token that already exists under a DIFFERENT user is reassigned rather
     * than rejected: that is precisely what happens when two colleagues share a
     * handset, and leaving the old owner attached would send them each other's
     * settlement alerts.
     */
    public function register(
        int $userId,
        ?int $organizationId,
        string $platform,
        string $token,
        ?string $deviceName = null,
        ?string $appVersion = null,
    ): PushDevice {
        /** @var PushDevice $device */
        $device = PushDevice::query()->updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'platform' => $platform,
                'device_name' => $deviceName,
                'app_version' => $appVersion,
                'last_seen_at' => Carbon::now(),
                // Re-registering un-retires a token: the app is demonstrably
                // alive on this device again.
                'revoked_at' => null,
                'revoked_reason' => null,
            ],
        );

        return $device;
    }

    /**
     * Retire a token.
     *
     * Deliberately indistinguishable for "no such token", "someone else's
     * token" and "already revoked": all three do nothing and report nothing.
     * Answering differently would turn this endpoint into an oracle that says
     * whether a given push token is registered on the platform.
     *
     * A soft revoke rather than a delete, per the table's own note — a deleted
     * row would be recreated by the next login and the provider failures would
     * start over.
     */
    public function revoke(int $userId, string $token, string $reason = 'revoked_by_user'): void
    {
        PushDevice::query()
            ->where('token', $token)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => Carbon::now(),
                'revoked_reason' => $reason,
            ]);
    }

    /**
     * The caller's live devices, for the "where do I get notifications?" screen.
     *
     * @return list<PushDevice>
     */
    public function activeFor(int $userId): array
    {
        /** @var list<PushDevice> $devices */
        $devices = PushDevice::query()
            ->active()
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->get()
            ->all();

        return $devices;
    }
}
