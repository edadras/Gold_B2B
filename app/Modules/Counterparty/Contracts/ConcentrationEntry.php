<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

use App\Modules\Counterparty\Domain\ConcentrationLevel;

/** One counterparty's slice of a member's total receivable. */
final readonly class ConcentrationEntry
{
    public function __construct(
        public int $counterpartyOrgId,
        public int $amount,
        public int $shareBps,
        public ConcentrationLevel $level,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'counterparty_org_id' => $this->counterpartyOrgId,
            'amount' => $this->amount,
            'share_bps' => $this->shareBps,
            'level' => $this->level->value,
        ];
    }
}
