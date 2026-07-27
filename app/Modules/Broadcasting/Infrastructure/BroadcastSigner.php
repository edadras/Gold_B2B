<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Infrastructure;

use App\Modules\Broadcasting\Domain\Exceptions\BroadcastNotConfiguredException;

/**
 * The Pusher-protocol channel authorisation signature, which Reverb verifies.
 *
 *     auth = "{app_key}:" + HMAC_SHA256("{socket_id}:{channel_name}", secret)
 *
 * Written out here rather than delegated to PusherBroadcaster::validAuth-
 * enticationResponse() for one practical reason: that path instantiates
 * `Pusher\Pusher`, and neither pusher/pusher-php-server nor laravel/reverb is
 * installed in this environment. The formula is six lines and is part of the
 * protocol, not of the package, so the endpoint works today under the `log`
 * driver and will keep working unchanged once Reverb is installed.
 *
 * The channel name signed is the one the CLIENT sent — `private-org.184`,
 * prefix included. Signing the normalised `org.184` instead would produce a
 * signature the socket server rejects, and is the classic way this endpoint is
 * got wrong.
 *
 * FAILS CLOSED. A missing secret raises rather than signing with '', because
 * HMAC with an empty key is a perfectly valid signature that any attacker can
 * also compute.
 */
final class BroadcastSigner
{
    public function __construct(
        private readonly ?string $appKey,
        private readonly ?string $appSecret,
    ) {}

    public function isConfigured(): bool
    {
        return is_string($this->appKey) && $this->appKey !== ''
            && is_string($this->appSecret) && $this->appSecret !== '';
    }

    /**
     * @param  string  $channelName  as sent by the client, prefix included
     *
     * @throws BroadcastNotConfiguredException when no app secret is configured
     */
    public function sign(string $socketId, string $channelName): string
    {
        if (! $this->isConfigured()) {
            throw new BroadcastNotConfiguredException;
        }

        $signature = hash_hmac('sha256', $socketId.':'.$channelName, (string) $this->appSecret);

        return $this->appKey.':'.$signature;
    }

    /**
     * Presence channels carry the member payload inside the signed string.
     * Nothing subscribes to one today — §3.2 lists no presence channel — but
     * the protocol difference is one line and omitting it would make a future
     * presence channel look like a signing bug.
     */
    public function signWithChannelData(string $socketId, string $channelName, string $channelData): string
    {
        if (! $this->isConfigured()) {
            throw new BroadcastNotConfiguredException;
        }

        $signature = hash_hmac(
            'sha256',
            $socketId.':'.$channelName.':'.$channelData,
            (string) $this->appSecret,
        );

        return $this->appKey.':'.$signature;
    }
}
