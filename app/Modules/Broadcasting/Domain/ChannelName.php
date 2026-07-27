<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Domain;

/**
 * The channel catalogue of docs/05-api/03-realtime-webhooks.md §3.2, in one
 * place, so a name is never spelled out twice.
 *
 * TWO SPELLINGS, ONE CHANNEL. The document (and the client) names the private
 * channels `private-org.184`, because the Pusher protocol requires the
 * `private-` prefix on the wire. Laravel strips that prefix before matching a
 * subscription against the patterns registered in routes/channels.php, so the
 * *registration* pattern is the bare `org.{organizationId}`. Both forms are
 * produced here:
 *
 *   ChannelName::organization(184)        => 'org.184'          (server side)
 *   ChannelName::wire('org.184')          => 'private-org.184'  (on the wire)
 *
 * Illuminate\Broadcasting\PrivateChannel does the same prefixing when an event
 * is serialised, so `new PrivateChannel(ChannelName::organization(184))` lands
 * on the client as exactly the `private-org.184` the document promises.
 */
final class ChannelName
{
    // --- registration patterns (routes/channels.php) -----------------------

    public const MARKET_PATTERN = 'market.{instrument}';

    public const MARKET_STATUS = 'market.status';

    public const REFERENCE_PRICE = 'reference-price';

    public const ORGANIZATION_PATTERN = 'org.{organizationId}';

    public const ORGANIZATION_SETTLEMENT_PATTERN = 'org.{organizationId}.settlement';

    public const ORGANIZATION_RFQ_PATTERN = 'org.{organizationId}.rfq';

    public const ORGANIZATION_NOTIFICATION_PATTERN = 'org.{organizationId}.notification';

    public const USER_PATTERN = 'user.{userId}';

    public const ADMIN_MONITORING = 'admin.monitoring';

    public const ADMIN_ALERTS = 'admin.alerts';

    /** A public market channel, one per instrument code. */
    public static function market(string $instrumentCode): string
    {
        return 'market.'.$instrumentCode;
    }

    public static function organization(int $organizationId): string
    {
        return 'org.'.$organizationId;
    }

    public static function organizationSettlement(int $organizationId): string
    {
        return 'org.'.$organizationId.'.settlement';
    }

    public static function organizationRfq(int $organizationId): string
    {
        return 'org.'.$organizationId.'.rfq';
    }

    public static function organizationNotification(int $organizationId): string
    {
        return 'org.'.$organizationId.'.notification';
    }

    public static function user(int $userId): string
    {
        return 'user.'.$userId;
    }

    /** The name as the client sends it: `private-org.184`. */
    public static function wire(string $registeredName): string
    {
        return 'private-'.$registeredName;
    }

    /**
     * The inverse of wire(): strips the protocol prefix a subscription carries.
     *
     * Mirrors Broadcaster::normalizeChannelName so the auth endpoint and the
     * channel registry agree on what "the channel" is called.
     */
    public static function normalize(string $wireName): string
    {
        foreach (['private-encrypted-', 'private-', 'presence-'] as $prefix) {
            if (str_starts_with($wireName, $prefix)) {
                return substr($wireName, strlen($prefix));
            }
        }

        return $wireName;
    }

    public static function isPrivate(string $wireName): bool
    {
        return str_starts_with($wireName, 'private-')
            || str_starts_with($wireName, 'presence-');
    }
}
