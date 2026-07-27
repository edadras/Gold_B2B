<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Unit;

use App\Modules\Webhook\Application\SignatureGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * §3.9 and docs/02-architecture/04-security.md §4.8.
 *
 * No application, no database: the signature is pure arithmetic over a secret, a
 * timestamp and a byte string, and it is asserted against a digest computed by
 * hand rather than against the implementation's own output. A test that checks
 * `sign()` against `sign()` would pass just as happily if the whole construction
 * were wrong.
 */
final class SignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const TIMESTAMP = 1735689600;

    private const BODY = '{"id":"evt_a3f9c2b14d5e6f7a","type":"trade.executed"}';

    /**
     * Independently computed:
     *   hash_hmac('sha256', '1735689600.{"id":"evt_a3f9…","type":"trade.executed"}', 'whsec_test_secret')
     */
    private const EXPECTED_HMAC = '79ced948fa5ccf8100c9955ecfd33c003d0f9c33d6f60d3498a50a91f84d7204';

    private SignatureGenerator $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new SignatureGenerator;
    }

    #[Test]
    public function the_digest_matches_a_hand_computed_hmac(): void
    {
        self::assertSame(
            self::EXPECTED_HMAC,
            $this->signer->signature(self::SECRET, self::BODY, self::TIMESTAMP),
        );
    }

    #[Test]
    public function the_header_has_the_documented_shape(): void
    {
        $header = $this->signer->sign(self::SECRET, self::BODY, self::TIMESTAMP);

        self::assertSame('t=1735689600,v1='.self::EXPECTED_HMAC, $header);

        $parts = SignatureGenerator::parseHeader($header);

        self::assertSame('1735689600', $parts['t']);
        self::assertSame(self::EXPECTED_HMAC, $parts['v1']);
    }

    #[Test]
    public function a_valid_signature_verifies_inside_the_window(): void
    {
        $header = $this->signer->sign(self::SECRET, self::BODY, self::TIMESTAMP);

        self::assertTrue(
            $this->signer->verify(self::SECRET, self::BODY, $header, 300, self::TIMESTAMP + 60),
        );
    }

    /** One byte, in the middle of a number, is enough. */
    #[Test]
    public function a_body_tampered_by_one_byte_is_rejected(): void
    {
        $header = $this->signer->sign(self::SECRET, self::BODY, self::TIMESTAMP);

        $tampered = str_replace('trade.executed', 'trade.executee', self::BODY);

        self::assertNotSame(self::BODY, $tampered);
        self::assertSame(strlen(self::BODY), strlen($tampered), 'the tamper must be a single byte substitution');

        self::assertFalse(
            $this->signer->verify(self::SECRET, $tampered, $header, 300, self::TIMESTAMP),
        );
    }

    #[Test]
    public function a_signature_from_the_wrong_secret_is_rejected(): void
    {
        $header = $this->signer->sign('whsec_someone_elses_secret', self::BODY, self::TIMESTAMP);

        self::assertFalse(
            $this->signer->verify(self::SECRET, self::BODY, $header, 300, self::TIMESTAMP),
        );
    }

    /**
     * The replay window of §4.8. A captured (body, signature) pair stays valid
     * for five minutes and not a second longer — and the same rule applies to a
     * timestamp in the future, which is how a clock-skew attack would be dressed
     * up.
     */
    #[Test]
    public function a_timestamp_outside_the_five_minute_window_is_rejected(): void
    {
        $header = $this->signer->sign(self::SECRET, self::BODY, self::TIMESTAMP);

        // 299 seconds late — still inside.
        self::assertTrue(
            $this->signer->verify(self::SECRET, self::BODY, $header, 300, self::TIMESTAMP + 299),
        );

        // 301 seconds late — a replay.
        self::assertFalse(
            $this->signer->verify(self::SECRET, self::BODY, $header, 300, self::TIMESTAMP + 301),
        );

        // 301 seconds early — a forged future timestamp.
        self::assertFalse(
            $this->signer->verify(self::SECRET, self::BODY, $header, 300, self::TIMESTAMP - 301),
        );
    }

    #[Test]
    public function malformed_headers_are_rejected_rather_than_throwing(): void
    {
        foreach (['', 'garbage', 't=,v1=', 't=abc,v1='.self::EXPECTED_HMAC, 'v1='.self::EXPECTED_HMAC] as $header) {
            self::assertFalse(
                $this->signer->verify(self::SECRET, self::BODY, $header, 300, self::TIMESTAMP),
                sprintf('header "%s" must not verify', $header),
            );
        }
    }

    /**
     * The §3.9 sample verifier, run verbatim against a header this module
     * produced. If this ever fails, every member's integration is broken.
     */
    #[Test]
    public function the_documented_receiver_sample_accepts_our_header(): void
    {
        $header = $this->signer->sign(self::SECRET, self::BODY, self::TIMESTAMP);

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = explode('=', $part, 2);
            $parts[$k] = $v;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = $parts['v1'] ?? '';

        $expected = hash_hmac('sha256', "{$timestamp}.".self::BODY, self::SECRET);

        self::assertTrue(hash_equals($expected, $signature));
    }
}
