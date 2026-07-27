<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * Side-by-side comparison of one account's cached balance against a balance
 * rebuilt from its entries — the drill-down of the reconciliation screen.
 */
final readonly class AccountRebuild
{
    public function __construct(
        public int $accountId,
        public int $organizationId,
        public ?string $organizationName,
        public string $assetType,
        public string $bucket,
        public ?string $systemAccountCode,
        public int $storedBalance,
        public int $rebuiltBalance,
        public int $storedEntryCount,
        public int $rebuiltEntryCount,
        public ?int $storedLastEntryId,
        public ?int $rebuiltLastEntryId,
    ) {}

    public function balanceDifference(): int
    {
        return $this->rebuiltBalance - $this->storedBalance;
    }

    public function matches(): bool
    {
        return $this->balanceDifference() === 0
            && $this->storedEntryCount === $this->rebuiltEntryCount
            && $this->storedLastEntryId === $this->rebuiltLastEntryId;
    }
}
