<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * A verified bank account money can be sent to.
 *
 * CONTRACT GAP RESOLVED (#2). The payment-declaration screen has to tell the
 * payer WHERE to send the money, but bank accounts live in Kyc and Settlement
 * may not depend on Kyc (module graph). Shared declares the port; Kyc binds it.
 *
 * Both a full and a masked IBAN are carried, because the two audiences are
 * different: the payer in a settlement genuinely needs the full number to make
 * the transfer, and everybody else must only ever see the mask. The decision of
 * which to render belongs to the resource that knows who is looking — see
 * SettlementResource.
 */
final readonly class PayoutAccount
{
    public function __construct(
        public int $organizationId,
        public string $bankName,
        public ?string $bankCode,
        public string $accountHolderName,
        public string $iban,
        public string $ibanMasked,
        public bool $verified,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(bool $revealIban = false): array
    {
        return [
            'bank_name' => $this->bankName,
            'bank_code' => $this->bankCode,
            'account_holder_name' => $this->accountHolderName,
            'iban' => $revealIban ? $this->iban : null,
            'iban_masked' => $this->ibanMasked,
            'verified' => $this->verified,
        ];
    }
}
