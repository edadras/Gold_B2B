<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Application;

use App\Modules\Broadcasting\Domain\ChannelName;

/**
 * Parses a subscription name into the one question the authorizer must answer.
 *
 * WHY A PARSER AND NOT A REGEX AT THE CALL SITE. The channel name is
 * attacker-controlled: it arrives verbatim in the body of
 * POST /api/v1/broadcasting/auth. Every id below is extracted with a strict
 * digits-only test and compared as an int, so none of these get through:
 *
 *   private-org.184abc      → not all digits, rejected
 *   private-org.0184        → leading zero, rejected (would otherwise be a
 *                             second spelling of a channel already granted)
 *   private-org.184.settlement.evil → suffix not recognised, rejected
 *   private-org.-1          → not digits, rejected
 *   private-org.99999999999999999999 → overflows int, rejected
 *
 * Anything unrecognised returns null and the authorizer denies. There is no
 * "close enough" branch.
 */
final readonly class ChannelPattern
{
    private function __construct(
        public string $normalizedName,
        public bool $isPublic = false,
        public bool $isPlatform = false,
        public ?int $organizationId = null,
        public ?int $userId = null,
        public ?string $scope = null,
    ) {}

    /** Suffixes of `org.{id}` that are channels in their own right (§3.2). */
    private const ORGANIZATION_SCOPES = ['settlement', 'rfq', 'notification'];

    public static function parse(string $wireChannelName): ?self
    {
        // Not trimmed. A name is signed exactly as the client sent it, so if
        // "private-org.184 " were quietly trimmed here the authorisation and
        // the signature would be about two different strings — and a trailing
        // space would become a second spelling of a granted channel.
        $name = ChannelName::normalize($wireChannelName);

        if ($name === '' || strlen($name) > 164 || preg_match('/\s/', $name) === 1) {
            return null;
        }

        // --- public channels ------------------------------------------------
        if ($name === ChannelName::MARKET_STATUS || $name === ChannelName::REFERENCE_PRICE) {
            return new self($name, isPublic: true);
        }

        if (str_starts_with($name, 'market.') && self::isInstrumentCode(substr($name, 7))) {
            return new self($name, isPublic: true);
        }

        // --- platform channels ----------------------------------------------
        if ($name === ChannelName::ADMIN_MONITORING || $name === ChannelName::ADMIN_ALERTS) {
            return new self($name, isPlatform: true);
        }

        // --- user channel ----------------------------------------------------
        if (str_starts_with($name, 'user.')) {
            $id = self::toId(substr($name, 5));

            return $id === null ? null : new self($name, userId: $id);
        }

        // --- organisation channels -------------------------------------------
        if (str_starts_with($name, 'org.')) {
            $parts = explode('.', substr($name, 4));

            if (count($parts) > 2) {
                return null;
            }

            $id = self::toId($parts[0]);

            if ($id === null) {
                return null;
            }

            $scope = $parts[1] ?? null;

            if ($scope !== null && ! in_array($scope, self::ORGANIZATION_SCOPES, true)) {
                return null;
            }

            return new self($name, organizationId: $id, scope: $scope);
        }

        return null;
    }

    /**
     * Digits only, no leading zero, and it must survive the int round trip.
     * `(int) $s` alone silently accepts "184abc" and saturates on overflow.
     */
    private static function toId(string $segment): ?int
    {
        if ($segment === '' || ! ctype_digit($segment)) {
            return null;
        }

        if (strlen($segment) > 1 && $segment[0] === '0') {
            return null;
        }

        $id = (int) $segment;

        return $id > 0 && (string) $id === $segment ? $id : null;
    }

    /**
     * Instrument codes are `VARCHAR(30)` of upper-case letters, digits and
     * dashes ("GOLD-995-T0"). Validated by shape rather than by lookup: this
     * runs on a public channel that needs no authorisation, so the only job is
     * to keep a junk name from being treated as one of ours.
     */
    private static function isInstrumentCode(string $code): bool
    {
        return $code !== ''
            && strlen($code) <= 30
            && preg_match('/^[A-Z0-9][A-Z0-9\-]*$/', $code) === 1;
    }
}
