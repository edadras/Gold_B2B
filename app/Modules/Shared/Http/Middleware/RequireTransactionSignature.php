<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Contracts\TransactionSigner;
use App\Modules\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "✍️ نیاز به تأیید تراکنش" gate of docs/05-api/02-endpoints.md.
 *
 * CONTRACT GAP RESOLVED (#1). The docs define the error code
 * AUTH_TRANSACTION_SIGN_REQUIRED but never named a header or a field, so the
 * Flutter client guessed and put `transaction_code` in the JSON body.
 *
 * The mechanism is now:
 *
 *   X-Transaction-Signature: 123456      ← canonical, a TOTP code
 *
 * A header, not a body field, for three reasons: the signature is metadata
 * about the request rather than part of the resource being created; it keeps
 * the signature out of the idempotency request hash, so replaying a stored
 * response does not require re-typing a code that has since expired; and it
 * keeps a short-lived secret out of request-body logs.
 *
 * The client's existing `transaction_code` body field is still accepted so the
 * shipped app keeps working, but responses carry `Deprecation: true` and a
 * Warning header pointing at the replacement. Remove the fallback in v2.
 *
 * Ordering: mount this BEFORE `idempotency`. A refused signature must not burn
 * an idempotency key, or the retry with a correct code would replay the 403.
 */
final class RequireTransactionSignature
{
    public const HEADER = 'X-Transaction-Signature';

    /** @deprecated The v1 Flutter client's guess; accepted until v2. */
    public const LEGACY_BODY_FIELD = 'transaction_code';

    public function __construct(private readonly TransactionSigner $signer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('AUTH_TOKEN_INVALID', 'برای این عملیات باید وارد شوید.', 401);
        }

        $userId = (int) $user->getAuthIdentifier();

        if (! $this->signer->isEnrolled($userId)) {
            // Not "forbidden": the member has to enrol a second factor first,
            // and the client needs to be told which screen to open.
            return $this->challenge($userId, 'two_factor_not_enrolled');
        }

        [$signature, $usedLegacyField] = $this->extractSignature($request);

        if ($signature === null || $signature === '') {
            return $this->challenge($userId, 'signature_missing');
        }

        if (! $this->signer->verify($userId, $signature)) {
            return ApiResponse::error(
                'AUTH_2FA_INVALID',
                'کد تأیید تراکنش نادرست است.',
                403,
                ['methods' => $this->signer->methodsFor($userId)],
            );
        }

        // Downstream code (audit trail, dual control) can see that this request
        // was signed without re-reading the header.
        $request->attributes->set('transaction_signed', true);

        $response = $next($request);

        if ($usedLegacyField) {
            $response->headers->set('Deprecation', 'true');
            $response->headers->set(
                'Warning',
                sprintf('299 - "Send the transaction signature in the %s header; the %s body field is removed in v2."', self::HEADER, self::LEGACY_BODY_FIELD),
            );
        }

        return $response;
    }

    /** @return array{0: string|null, 1: bool} the signature and whether it came from the legacy field */
    private function extractSignature(Request $request): array
    {
        $header = $request->header(self::HEADER);

        if (is_string($header) && $header !== '') {
            return [trim($header), false];
        }

        $legacy = $request->input(self::LEGACY_BODY_FIELD);

        return is_string($legacy) && $legacy !== '' ? [trim($legacy), true] : [null, false];
    }

    private function challenge(int $userId, string $reason): Response
    {
        return ApiResponse::error(
            'AUTH_TRANSACTION_SIGN_REQUIRED',
            'برای انجام این عملیات باید کد تأیید تراکنش را وارد کنید.',
            403,
            [
                'reason' => $reason,
                'header' => self::HEADER,
                'methods' => $this->signer->methodsFor($userId),
            ],
        );
    }
}
