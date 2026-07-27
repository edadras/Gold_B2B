<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;

/**
 * RFC 6238 time-based one-time passwords over RFC 4226 HOTP.
 *
 * Implemented here rather than pulled in as a composer package: the algorithm
 * is thirty lines, and second-factor verification is on the critical path for
 * every financial action, so we keep it auditable and dependency-free.
 *
 * Secrets are RFC 4648 base32 (no padding), which is what authenticator apps
 * expect in an otpauth:// URI.
 */
final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    /**
     * Number of periods either side of "now" that still verify. One step of
     * tolerance covers clock skew without meaningfully widening the window.
     */
    public const DEFAULT_WINDOW = 1;

    private function __construct() {}

    /** Generate a fresh base32 secret. 20 bytes = 160 bits, the RFC 4226 default. */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            throw new InvalidArgumentException('TOTP secret must be at least 80 bits');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    /** The 6-digit code for a given secret and unix timestamp. */
    public static function codeAt(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, self::PERIOD_SECONDS);

        return self::hotp(self::base32Decode($secret), $counter);
    }

    public static function code(string $secret): string
    {
        return self::codeAt($secret, time());
    }

    /**
     * Constant-time comparison against every code in the tolerance window.
     *
     * Replay protection (remembering the last accepted counter per user) is the
     * caller's job — see AuthService::verifyTwoFactor.
     */
    public static function verify(
        string $secret,
        string $code,
        ?int $timestamp = null,
        int $window = self::DEFAULT_WINDOW,
    ): bool {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $timestamp ??= time();
        $key = self::base32Decode($secret);

        if ($key === '') {
            return false;
        }

        $counter = intdiv($timestamp, self::PERIOD_SECONDS);
        $matched = false;

        for ($drift = -$window; $drift <= $window; $drift++) {
            // No early return: keep the number of HMACs independent of where
            // the match occurs.
            $matched = hash_equals(self::hotp($key, $counter + $drift), $code) || $matched;
        }

        return $matched;
    }

    /** otpauth:// provisioning URI for QR display. */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD_SECONDS,
        );
    }

    /** RFC 4226 HOTP with dynamic truncation. */
    private static function hotp(string $key, int $counter): string
    {
        // 8-byte big-endian counter.
        $binCounter = pack('J', $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($truncated % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }

    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0');

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    /** Returns '' for input that is not valid base32. */
    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace(['=', ' ', '-'], '', $secret));

        if ($secret === '' || strspn($secret, self::BASE32_ALPHABET) !== strlen($secret)) {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }
}
