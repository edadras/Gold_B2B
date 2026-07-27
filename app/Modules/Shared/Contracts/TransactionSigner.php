<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Re-authentication for a high-value action — the "✍️" column of
 * docs/05-api/02-endpoints.md and §4.2 "Transaction Signing".
 *
 * Declared here so the `transaction.sign` middleware can live in Shared while
 * the TOTP implementation stays in Identity.
 */
interface TransactionSigner
{
    /** True when the user has an enrolled, confirmed second factor to sign with. */
    public function isEnrolled(int $userId): bool;

    /**
     * Verify a signature for one action. Implementations MUST burn the code so
     * it cannot be replayed inside its own time window.
     */
    public function verify(int $userId, string $signature): bool;

    /** @return list<string> methods this user may sign with, e.g. ["TOTP"] */
    public function methodsFor(int $userId): array;
}
