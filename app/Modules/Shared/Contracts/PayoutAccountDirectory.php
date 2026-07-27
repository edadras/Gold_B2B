<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Where an organisation is paid.
 *
 * Declared in Shared so Settlement can answer "who do I pay, and to which
 * account?" without importing Kyc, which owns `bank_accounts`.
 */
interface PayoutAccountDirectory
{
    /** The verified primary account, or null when the member has none. */
    public function primaryFor(int $organizationId): ?PayoutAccount;
}
