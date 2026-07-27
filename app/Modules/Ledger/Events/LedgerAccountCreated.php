<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Events;

/**
 * A ledger account was provisioned. Scalars only — no Eloquent models cross a
 * module boundary (AGENT_BRIEF rule 7).
 */
final readonly class LedgerAccountCreated
{
    public function __construct(
        public int $accountId,
        public int $organizationId,
        public string $assetType,
        public ?string $metalType,
        public string $bucket,
        public ?string $systemAccountCode,
        public bool $allowsNegative,
    ) {}
}
