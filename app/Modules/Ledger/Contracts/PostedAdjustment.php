<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Contracts;

/**
 * What a posted manual adjustment left behind: one transaction group holding
 * exactly two balanced legs.
 */
final readonly class PostedAdjustment
{
    /** @param list<int> $entryIds */
    public function __construct(
        public string $transactionGroup,
        public array $entryIds,
        public int $memberAccountId,
        public int $offsetAccountId,
    ) {}
}
