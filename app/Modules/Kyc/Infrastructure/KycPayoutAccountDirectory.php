<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure;

use App\Modules\Kyc\Domain\BankAccountStatus;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Shared\Contracts\PayoutAccount;
use App\Modules\Shared\Contracts\PayoutAccountDirectory;

/**
 * Kyc's answer to the payout-account port Shared declared for Settlement.
 *
 * Only a VERIFIED account is ever returned: a payer must not be handed an IBAN
 * the platform has not yet checked, because the whole point of verification is
 * that money sent there reaches the member it claims to.
 */
final class KycPayoutAccountDirectory implements PayoutAccountDirectory
{
    public function primaryFor(int $organizationId): ?PayoutAccount
    {
        /** @var BankAccount|null $account */
        $account = BankAccount::query()
            ->where('organization_id', $organizationId)
            ->where('status', BankAccountStatus::VERIFIED->value)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($account === null) {
            return null;
        }

        return new PayoutAccount(
            organizationId: $organizationId,
            bankName: (string) $account->bank_name,
            bankCode: $account->bank_code === null ? null : (string) $account->bank_code,
            accountHolderName: (string) $account->account_holder_name,
            iban: (string) $account->iban_enc,
            ibanMasked: $account->maskedIban(),
            verified: true,
        );
    }
}
