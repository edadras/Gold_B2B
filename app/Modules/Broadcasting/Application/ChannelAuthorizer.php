<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Application;

use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\UserSnapshot;
use App\Modules\Identity\Domain\Role;

/**
 * THE SECURITY BOUNDARY OF THE REALTIME LAYER.
 *
 * A WebSocket subscription is not a request. It is authorised once, at
 * subscribe time, and then streams for as long as the connection lives — so a
 * mistake here is not one leaked response, it is a live feed of another
 * member's orders, balances and counterparties for the rest of the session.
 * Everything below is written to fail closed.
 *
 * THE RULES
 *
 *   · `org.{id}` and its `.settlement` / `.rfq` / `.notification` children:
 *     the caller's own organisation id must equal `{id}`. Integer identity, no
 *     casting of a client-supplied string into a number that might match, no
 *     "is a member of" list — a user in this platform belongs to exactly one
 *     organisation (Identity\...\User: «always scoped to exactly one»).
 *
 *   · `user.{id}`: the caller and nobody else. Not a colleague, not the OWNER
 *     of the same organisation. Personal channels carry OTP prompts and device
 *     notices.
 *
 *   · `admin.monitoring` / `admin.alerts`: platform staff only, decided by
 *     Role::isPlatformRole() over the caller's roles. A member's OWNER is not
 *     staff no matter how senior; the two role families are disjoint by
 *     construction in Identity\Domain\Role.
 *
 * PLATFORM STAFF DO NOT GET MEMBER CHANNELS. A compliance officer who needs a
 * member's order flow reads it through the admin REST endpoints, which audit
 * the access. Granting staff a blanket subscription to `private-org.*` would
 * create an unaudited, unrevoked firehose over every member on the platform,
 * and "the admin panel needs it" is how that gets justified. It does not.
 *
 * NON-ACTIVE USERS ARE REFUSED. A suspended user may still hold an unexpired
 * Sanctum token; the token is not the authority, the account state is.
 */
final class ChannelAuthorizer
{
    /** UserStatus::ACTIVE — compared as a string so Identity's enum is not a dependency of the check. */
    private const ACTIVE = 'ACTIVE';

    public function __construct(private readonly IdentityDirectory $identity) {}

    /** May this user subscribe to any channel scoped to this organisation? */
    public function mayJoinOrganization(int $userId, int $organizationId): bool
    {
        $user = $this->activeUser($userId);

        if ($user === null) {
            return false;
        }

        return $user->organizationId === $organizationId;
    }

    /** May this user subscribe to this person's private channel? */
    public function mayJoinUser(int $userId, int $targetUserId): bool
    {
        return $this->activeUser($userId)?->id === $targetUserId;
    }

    /** May this user subscribe to the platform operations channels? */
    public function mayJoinPlatform(int $userId): bool
    {
        $user = $this->activeUser($userId);

        if ($user === null) {
            return false;
        }

        foreach ($user->roles as $roleName) {
            if (! is_string($roleName)) {
                continue;
            }

            if (Role::tryFrom($roleName)?->isPlatformRole() === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole-channel decision, used by the auth endpoint.
     *
     * Takes the name AS THE CLIENT SENT IT and normalises internally, so the
     * endpoint cannot forget to strip `private-` and accidentally fall through
     * to the deny-by-default branch — or, worse, match a pattern it should
     * not.
     */
    public function mayJoin(int $userId, string $wireChannelName): bool
    {
        $channel = ChannelPattern::parse($wireChannelName);

        return match (true) {
            $channel === null => false,
            $channel->isPublic => true,
            $channel->organizationId !== null => $this->mayJoinOrganization($userId, $channel->organizationId),
            $channel->userId !== null => $this->mayJoinUser($userId, $channel->userId),
            $channel->isPlatform => $this->mayJoinPlatform($userId),
            // Unknown private channel: denied. A channel nobody registered is
            // not a channel anybody may listen to.
            default => false,
        };
    }

    private function activeUser(int $userId): ?UserSnapshot
    {
        $user = $this->identity->findUser($userId);

        if ($user === null || $user->status !== self::ACTIVE) {
            return null;
        }

        return $user;
    }
}
