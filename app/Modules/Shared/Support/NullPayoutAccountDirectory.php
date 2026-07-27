<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Contracts\PayoutAccount;
use App\Modules\Shared\Contracts\PayoutAccountDirectory;

/** Default binding for a slice without Kyc: nobody has a payout account. */
final class NullPayoutAccountDirectory implements PayoutAccountDirectory
{
    public function primaryFor(int $organizationId): ?PayoutAccount
    {
        return null;
    }
}
