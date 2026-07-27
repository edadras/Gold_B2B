<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain;

/**
 * "Is this IP address somewhere the platform is allowed to send a POST?"
 *
 * Pure, static, no DNS, no network — the resolving half lives in
 * Application\OutboundUrlGuard, which is where the reasoning for the whole SSRF
 * control is written down. This class is only the address arithmetic, kept
 * separate so it can be unit-tested against a table of addresses without a
 * resolver in the way.
 *
 * The deny list is deliberately *positive*: an address is blocked unless it is
 * outside every listed range. PHP's FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE is
 * not used as the primary control because its definition of "reserved" has moved
 * between PHP versions and it does not cover 100.64.0.0/10 (carrier NAT, where a
 * cloud metadata proxy is perfectly capable of living). An explicit table is
 * auditable; a flag is a promise.
 */
final class IpRangePolicy
{
    /**
     * IPv4 ranges that must never be a webhook destination.
     *
     * @var list<array{0: string, 1: int, 2: string}> [network, prefix, why]
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8, 'this network / unspecified'],
        ['10.0.0.0', 8, 'RFC1918 private'],
        ['100.64.0.0', 10, 'RFC6598 carrier-grade NAT'],
        ['127.0.0.0', 8, 'loopback — the application server itself'],
        ['169.254.0.0', 16, 'RFC3927 link-local, incl. 169.254.169.254 cloud metadata'],
        ['172.16.0.0', 12, 'RFC1918 private'],
        ['192.0.0.0', 24, 'IETF protocol assignments'],
        ['192.0.2.0', 24, 'TEST-NET-1'],
        ['192.88.99.0', 24, '6to4 relay anycast'],
        ['192.168.0.0', 16, 'RFC1918 private'],
        ['198.18.0.0', 15, 'benchmarking'],
        ['198.51.100.0', 24, 'TEST-NET-2'],
        ['203.0.113.0', 24, 'TEST-NET-3'],
        ['224.0.0.0', 4, 'multicast'],
        ['240.0.0.0', 4, 'reserved, incl. 255.255.255.255 broadcast'],
    ];

    /**
     * IPv6 ranges. IPv4-mapped and 6to4 addresses are unwrapped to their IPv4
     * form before the table is consulted, so `::ffff:127.0.0.1` cannot smuggle
     * loopback past a v6 check.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    private const BLOCKED_V6 = [
        ['::', 128, 'unspecified'],
        ['::1', 128, 'loopback'],
        ['64:ff9b::', 96, 'NAT64 — translates straight back to IPv4'],
        ['100::', 64, 'discard-only'],
        ['2001:db8::', 32, 'documentation'],
        ['fc00::', 7, 'unique local address'],
        ['fe80::', 10, 'link-local'],
        ['ff00::', 8, 'multicast'],
    ];

    /** True when the address may be used as an outbound webhook destination. */
    public static function isPublic(string $ip): bool
    {
        return self::reasonForBlocking($ip) === null;
    }

    /** Human-readable reason, or null when the address is acceptable. */
    public static function reasonForBlocking(string $ip): ?string
    {
        $ip = self::unwrap($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4 as [$network, $prefix, $why]) {
                if (self::inRange($ip, $network, $prefix)) {
                    return $why;
                }
            }

            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            foreach (self::BLOCKED_V6 as [$network, $prefix, $why]) {
                if (self::inRange($ip, $network, $prefix)) {
                    return $why;
                }
            }

            return null;
        }

        return 'not an IP address';
    }

    /**
     * `::ffff:a.b.c.d` and `2002:xxyy:zzww::/16` carry an IPv4 address inside a
     * v6 one. Both are handed to the kernel as v6 and both route to the embedded
     * v4 destination, so they are rewritten to that destination before checking.
     */
    private static function unwrap(string $ip): string
    {
        $binary = @inet_pton($ip);

        if ($binary === false || strlen($binary) !== 16) {
            return $ip;
        }

        // ::ffff:0:0/96 — IPv4-mapped.
        if (str_starts_with($binary, str_repeat("\x00", 10)."\xff\xff")) {
            return inet_ntop(substr($binary, 12)) ?: $ip;
        }

        // 2002::/16 — 6to4, the embedded v4 address is bytes 2..5.
        if (str_starts_with($binary, "\x20\x02")) {
            return inet_ntop(substr($binary, 2, 4)) ?: $ip;
        }

        return $ip;
    }

    /** Prefix-length containment, done on the packed bytes so v4 and v6 share it. */
    private static function inRange(string $ip, string $network, int $prefix): bool
    {
        $address = @inet_pton($ip);
        $base = @inet_pton($network);

        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($address, $base, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($base[$wholeBytes]) & $mask);
    }
}
