<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

/**
 * How much more credit a member is still willing to extend to one counterparty
 * (docs/03-domain/10-counterparty.md §10.5).
 *
 * "Used" is the receivable side of the balance only: when the counterparty owes
 * us 250 g, 250 g of the gold limit is consumed. When *we* owe *them*, none of
 * our limit is used — that is their exposure to us, governed by their own limit.
 */
final readonly class CreditHeadroom
{
    public function __construct(
        public int $organizationId,
        public int $counterpartyOrgId,
        public int $goldLimitMg,
        public int $goldUsedMg,
        public int $goldRemainingMg,
        public int $rialLimit,
        public int $rialUsed,
        public int $rialRemaining,
    ) {}

    public function allowsAdditionalGold(int $goldMg): bool
    {
        return $goldMg <= $this->goldRemainingMg;
    }

    public function allowsAdditionalRial(int $rial): bool
    {
        return $rial <= $this->rialRemaining;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'counterparty_org_id' => $this->counterpartyOrgId,
            'gold_limit_mg' => $this->goldLimitMg,
            'gold_used_mg' => $this->goldUsedMg,
            'gold_remaining_mg' => $this->goldRemainingMg,
            'rial_limit' => $this->rialLimit,
            'rial_used' => $this->rialUsed,
            'rial_remaining' => $this->rialRemaining,
        ];
    }
}
