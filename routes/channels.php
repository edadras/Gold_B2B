<?php

declare(strict_types=1);

use App\Modules\Broadcasting\Application\ChannelAuthorizer;
use App\Modules\Broadcasting\Domain\ChannelName;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels — docs/05-api/03-realtime-webhooks.md §3.2
|--------------------------------------------------------------------------
|
| Loaded by App\Modules\Broadcasting\BroadcastingServiceProvider.
|
| THE `private-` PREFIX IS NOT WRITTEN HERE, AND THAT IS NOT AN OMISSION.
| The document — and the client — names these channels `private-org.184`,
| because the Pusher protocol requires that prefix on the wire. Laravel strips
| it (Broadcaster::normalizeChannelName) before matching a subscription against
| the patterns below, so registering the literal `private-org.{id}` would
| produce a pattern nothing can ever match and every private subscription would
| be refused. The bare name is the server-side spelling of the same channel;
| ChannelName::wire() converts back, and Illuminate's PrivateChannel re-applies
| the prefix when an event is serialised, so what lands on the client is
| exactly the `private-org.184` §3.2 promises.
|
| Every decision below delegates to ChannelAuthorizer. Nothing is decided in
| this file: it is a routing table, and the security argument lives in one
| class with one test suite pointed at it.
|
| PUBLIC CHANNELS ARE LISTED BUT NEVER CONSULTED. A Pusher-protocol client
| subscribes to a public channel without an authorisation round trip, so these
| three callbacks are unreachable in normal operation. They are registered so
| that `php artisan channel:list` shows the platform's full realtime surface in
| one place, and so that the more specific `market.status` is matched before
| the `market.{instrument}` wildcard that would otherwise swallow it.
|
*/

$authorizer = static fn (): ChannelAuthorizer => app(ChannelAuthorizer::class);

// --- public: no authorisation, listed for visibility -------------------------

Broadcast::channel(ChannelName::MARKET_STATUS, static fn (): bool => true);

Broadcast::channel(ChannelName::REFERENCE_PRICE, static fn (): bool => true);

Broadcast::channel(ChannelName::MARKET_PATTERN, static fn (): bool => true);

// --- private: the member's own organisation ----------------------------------
//
// `org.{organizationId}` and its three children are four separate channels so
// a client can subscribe to only what it renders — a settlement screen does
// not want the order flow, and a mobile app in the background wants neither.
// All four ask the same question, because they carry the same tenant's data.

Broadcast::channel(
    ChannelName::ORGANIZATION_PATTERN,
    static fn (Authenticatable $user, string $organizationId): bool => $authorizer()
        ->mayJoinOrganization((int) $user->getAuthIdentifier(), (int) $organizationId),
);

Broadcast::channel(
    ChannelName::ORGANIZATION_SETTLEMENT_PATTERN,
    static fn (Authenticatable $user, string $organizationId): bool => $authorizer()
        ->mayJoinOrganization((int) $user->getAuthIdentifier(), (int) $organizationId),
);

Broadcast::channel(
    ChannelName::ORGANIZATION_RFQ_PATTERN,
    static fn (Authenticatable $user, string $organizationId): bool => $authorizer()
        ->mayJoinOrganization((int) $user->getAuthIdentifier(), (int) $organizationId),
);

Broadcast::channel(
    ChannelName::ORGANIZATION_NOTIFICATION_PATTERN,
    static fn (Authenticatable $user, string $organizationId): bool => $authorizer()
        ->mayJoinOrganization((int) $user->getAuthIdentifier(), (int) $organizationId),
);

// --- private: one person -----------------------------------------------------
//
// Narrower than the organisation channels, deliberately: an OWNER may read
// every order their organisation places, and still has no business on a
// colleague's personal channel.

Broadcast::channel(
    ChannelName::USER_PATTERN,
    static fn (Authenticatable $user, string $userId): bool => $authorizer()
        ->mayJoinUser((int) $user->getAuthIdentifier(), (int) $userId),
);

// --- private: platform staff only --------------------------------------------
//
// Decided by Identity\Domain\Role::isPlatformRole(). A member's OWNER is not
// staff, no matter how large the member is; the two role families are disjoint
// by construction.

Broadcast::channel(
    ChannelName::ADMIN_MONITORING,
    static fn (Authenticatable $user): bool => $authorizer()
        ->mayJoinPlatform((int) $user->getAuthIdentifier()),
);

Broadcast::channel(
    ChannelName::ADMIN_ALERTS,
    static fn (Authenticatable $user): bool => $authorizer()
        ->mayJoinPlatform((int) $user->getAuthIdentifier()),
);
