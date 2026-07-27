<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Resources;

use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A member bank account.
 *
 * SECURITY — the raw IBAN never leaves the server.
 *
 * `iban_enc` is the encrypted column and `iban_hash` the blind index; neither
 * is listed here, and the only IBAN-shaped value in the payload is
 * BankAccount::maskedIban(), which keeps the first six characters (country,
 * check digits and bank code — enough for the member to recognise the account)
 * and the last four, and replaces the middle with bullets.
 *
 * Why masking rather than "it is only the owner reading it": the blind index is
 * unique platform-wide, so a full IBAN in a response body is a value an
 * attacker who has compromised one member's token could carry to a support
 * channel as proof of identity. It is also a payment instruction — the field
 * most worth tampering with in a compromised client. There is no product
 * requirement that the client can read it back; it typed it.
 *
 * @mixin BankAccount
 */
final class BankAccountResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BankAccount $account */
        $account = $this->resource;

        return [
            'id' => (int) $account->id,
            'organization_id' => (int) $account->organization_id,
            'iban_masked' => $account->maskedIban(),
            'bank_name' => (string) $account->bank_name,
            'bank_code' => $account->bank_code,
            'account_holder_name' => (string) $account->account_holder_name,
            'account_no' => $account->account_no,
            'is_primary' => (bool) $account->is_primary,
            'status' => $account->status->value,
            'rejection_reason' => $account->rejection_reason,
            'document_id' => $account->document_id === null ? null : (int) $account->document_id,
            'verified_at' => Display::iso($account->verified_at),
            'created_at' => Display::iso($account->created_at),
        ] + $this->display($request, [
            'verified_at_jalali' => Display::jalali($account->verified_at),
            'created_at_jalali' => Display::jalali($account->created_at),
        ]);
    }
}
