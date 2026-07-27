<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\TransactionSigner;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;

/**
 * Transaction signing backed by the same TOTP secret the user enrolled for
 * login (docs/02-architecture/04-security.md §4.2).
 *
 * AuthService::confirmTransaction() burns the counter, so a signature captured
 * from one request cannot be replayed on the next inside its 30-second window —
 * which is the whole point of signing a withdrawal rather than trusting the
 * bearer token.
 *
 * @internal bound to TransactionSigner by IdentityServiceProvider
 */
final class TotpTransactionSigner implements TransactionSigner
{
    public function __construct(private readonly AuthService $auth) {}

    public function isEnrolled(int $userId): bool
    {
        return $this->user($userId)?->hasTwoFactorEnabled() ?? false;
    }

    public function verify(int $userId, string $signature): bool
    {
        $user = $this->user($userId);

        if ($user === null) {
            return false;
        }

        try {
            return $this->auth->confirmTransaction($user, $signature);
        } catch (OperationNotPermittedException) {
            // Not enrolled. The middleware asks isEnrolled() first, so this is
            // only reachable if enrolment was revoked mid-request.
            return false;
        }
    }

    public function methodsFor(int $userId): array
    {
        return $this->isEnrolled($userId) ? ['TOTP'] : [];
    }

    private function user(int $userId): ?User
    {
        /** @var User|null */
        return User::query()->find($userId);
    }
}
