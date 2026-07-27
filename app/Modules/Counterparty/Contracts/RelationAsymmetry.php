<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

/**
 * A violated symmetry invariant: the two rows of one pair no longer mirror.
 *
 * `kind` distinguishes the two failure modes, which have different causes and
 * different fixes:
 *  - ASYMMETRIC — both rows exist but disagree (a half-applied write);
 *  - ORPHAN     — one row exists without its mirror (an interrupted insert).
 */
final readonly class RelationAsymmetry
{
    public const ASYMMETRIC = 'ASYMMETRIC';

    public const ORPHAN = 'ORPHAN';

    public const DRIFT = 'DRIFT';

    public function __construct(
        public string $kind,
        public int $organizationId,
        public int $counterpartyOrgId,
        public int $goldMg,
        public ?int $mirrorGoldMg,
        public int $rial,
        public ?int $mirrorRial,
    ) {}

    public function goldGapMg(): int
    {
        return $this->goldMg + ($this->mirrorGoldMg ?? 0);
    }

    public function rialGap(): int
    {
        return $this->rial + ($this->mirrorRial ?? 0);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'organization_id' => $this->organizationId,
            'counterparty_org_id' => $this->counterpartyOrgId,
            'gold_mg' => $this->goldMg,
            'mirror_gold_mg' => $this->mirrorGoldMg,
            'rial' => $this->rial,
            'mirror_rial' => $this->mirrorRial,
            'gold_gap_mg' => $this->goldGapMg(),
            'rial_gap' => $this->rialGap(),
        ];
    }
}
