<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Validators\MobileNormalizer;
use App\Modules\Identity\Events\OtpRequested;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * One-time codes for `POST /auth/otp/send` and `/auth/otp/verify`, and for the
 * password-reset flow (docs/05-api/02-endpoints.md §2.1).
 *
 * The code lives in the cache, never in a table: it is valid for two minutes
 * and a durable copy would only be a liability. The delivery channel is not
 * this module's business — Identity may not depend on Notification, so it
 * raises OtpRequested and Notification listens.
 *
 * Verification returns a short-lived *verification ticket* rather than a
 * boolean. The ticket is what /auth/password/reset and registration accept, so
 * a caller cannot skip the OTP step by simply claiming it succeeded.
 */
final class OtpService
{
    public const CODE_TTL_SECONDS = 120;

    public const TICKET_TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    /** @return array{mobile: string, expires_in: int, debug_code: string|null} */
    public function send(string $mobile, string $purpose, array $context = []): array
    {
        $normalized = $this->normalize($mobile);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->codeKey($normalized, $purpose), [
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
        ], self::CODE_TTL_SECONDS);

        Event::dispatch(new OtpRequested(
            mobile: $normalized,
            purpose: $purpose,
            code: $code,
            expiresInSeconds: self::CODE_TTL_SECONDS,
            occurredAt: now()->toIso8601String(),
        ));

        return [
            'mobile' => $normalized,
            'expires_in' => self::CODE_TTL_SECONDS,
            // Only ever populated outside production, so an integration test or
            // a sandbox client can complete the flow without an SMS gateway.
            'debug_code' => app()->isProduction() ? null : $code,
        ];
    }

    /**
     * @return string|null the verification ticket, or null when the code is wrong
     */
    public function verify(string $mobile, string $purpose, string $code): ?string
    {
        $normalized = $this->normalize($mobile);
        $key = $this->codeKey($normalized, $purpose);

        /** @var array{code_hash: string, attempts: int}|null $entry */
        $entry = Cache::get($key);

        if ($entry === null) {
            return null;
        }

        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return null;
        }

        if (! hash_equals($entry['code_hash'], hash('sha256', $code))) {
            $entry['attempts']++;
            Cache::put($key, $entry, self::CODE_TTL_SECONDS);

            return null;
        }

        Cache::forget($key);

        $ticket = bin2hex(random_bytes(24));
        Cache::put($this->ticketKey($ticket), [
            'mobile' => $normalized,
            'purpose' => $purpose,
        ], self::TICKET_TTL_SECONDS);

        return $ticket;
    }

    /** Consume a ticket. Single use: a reset link cannot be replayed. */
    public function consumeTicket(string $ticket, string $purpose, string $mobile): bool
    {
        /** @var array{mobile: string, purpose: string}|null $entry */
        $entry = Cache::get($this->ticketKey($ticket));

        if ($entry === null || $entry['purpose'] !== $purpose) {
            return false;
        }

        if ($entry['mobile'] !== $this->normalize($mobile)) {
            return false;
        }

        Cache::forget($this->ticketKey($ticket));

        return true;
    }

    private function normalize(string $mobile): string
    {
        return MobileNormalizer::normalize($mobile)
            ?? throw new InvalidArgumentException('Invalid Iranian mobile number');
    }

    private function codeKey(string $mobile, string $purpose): string
    {
        return "identity:otp:{$purpose}:{$mobile}";
    }

    private function ticketKey(string $ticket): string
    {
        return "identity:otp-ticket:{$ticket}";
    }
}
