<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * The single surviving transfer between one pair — F12 of
 * docs/11-appendix/01-formulas.md §1.6.
 *
 *   net(A→B) = Σ obligations(A→B) − Σ obligations(B→A)
 *
 * A zero net means the pair cancels out completely and no transfer happens at
 * all, which is why isSettledOut() exists and why such pairs are excluded from
 * net_transfer_count.
 */
final readonly class BilateralNet
{
    public function __construct(
        public int $lowOrganizationId,
        public int $highOrganizationId,
        /** Signed: positive = low owes high, negative = high owes low. */
        public int $net,
        public int $forwardGross = 0,
        public int $reverseGross = 0,
        public int $obligationCount = 0,
    ) {}

    public function isSettledOut(): bool
    {
        return $this->net === 0;
    }

    public function amount(): int
    {
        return abs($this->net);
    }

    /** The organisation that ends up paying, or null when the pair cancels. */
    public function payerOrganizationId(): ?int
    {
        return match (true) {
            $this->net > 0 => $this->lowOrganizationId,
            $this->net < 0 => $this->highOrganizationId,
            default => null,
        };
    }

    /** The organisation that ends up receiving, or null when the pair cancels. */
    public function receiverOrganizationId(): ?int
    {
        return match (true) {
            $this->net > 0 => $this->highOrganizationId,
            $this->net < 0 => $this->lowOrganizationId,
            default => null,
        };
    }

    /** @return array<string, int|null> */
    public function toArray(): array
    {
        return [
            'low_organization_id' => $this->lowOrganizationId,
            'high_organization_id' => $this->highOrganizationId,
            'net' => $this->net,
            'payer_organization_id' => $this->payerOrganizationId(),
            'receiver_organization_id' => $this->receiverOrganizationId(),
            'amount' => $this->amount(),
        ];
    }
}
