<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Unit;

use App\Modules\Identity\Domain\Totp;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 Appendix B test vectors, plus the base32 round trip.
 *
 * The RFC's vectors use the ASCII secret "12345678901234567890"; we encode it
 * to base32 first because that is the form our column stores.
 */
final class TotpTest extends TestCase
{
    private const RFC_SECRET_ASCII = '12345678901234567890';

    public function test_matches_rfc_6238_sha1_vectors(): void
    {
        $secret = Totp::base32Encode(self::RFC_SECRET_ASCII);

        // timestamp => expected 8-digit code, truncated to our 6 digits.
        $vectors = [
            59 => '287082',
            1_111_111_109 => '081804',
            1_111_111_111 => '050471',
            1_234_567_890 => '005924',
            2_000_000_000 => '279037',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame(
                $expected,
                Totp::codeAt($secret, $timestamp),
                "RFC 6238 vector at t={$timestamp}",
            );
        }
    }

    public function test_base32_round_trips(): void
    {
        $this->assertSame(
            self::RFC_SECRET_ASCII,
            Totp::base32Decode(Totp::base32Encode(self::RFC_SECRET_ASCII)),
        );

        $binary = random_bytes(20);
        $this->assertSame($binary, Totp::base32Decode(Totp::base32Encode($binary)));
    }

    public function test_generated_secrets_are_usable_base32(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame('', Totp::base32Decode($secret));
    }

    public function test_verify_accepts_the_current_code(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $this->assertTrue(Totp::verify($secret, Totp::codeAt($secret, $now), $now));
    }

    public function test_verify_tolerates_one_step_of_clock_drift(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $previous = Totp::codeAt($secret, $now - Totp::PERIOD_SECONDS);
        $next = Totp::codeAt($secret, $now + Totp::PERIOD_SECONDS);

        $this->assertTrue(Totp::verify($secret, $previous, $now));
        $this->assertTrue(Totp::verify($secret, $next, $now));
    }

    public function test_verify_rejects_codes_beyond_the_window(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $stale = Totp::codeAt($secret, $now - (5 * Totp::PERIOD_SECONDS));

        $this->assertFalse(Totp::verify($secret, $stale, $now));
    }

    public function test_verify_rejects_malformed_input(): void
    {
        $secret = Totp::generateSecret();

        $this->assertFalse(Totp::verify($secret, ''));
        $this->assertFalse(Totp::verify($secret, '12345'));
        $this->assertFalse(Totp::verify($secret, 'abcdef'));
        $this->assertFalse(Totp::verify('not-base32!', '123456'));
    }

    public function test_provisioning_uri_is_well_formed(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', '989121234567', 'Gold B2B');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
