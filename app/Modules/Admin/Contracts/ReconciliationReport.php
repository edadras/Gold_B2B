<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * Result of a read-only reconciliation pass. A reconciliation never repairs a
 * ledger; it reports, and a human decides (docs/03-domain/03-ledger.md §3.7).
 */
final readonly class ReconciliationReport
{
    /**
     * @param  list<LedgerDiscrepancy>  $discrepancies
     * @param  list<array{account_id: int, organization_id: int, asset_type: string, bucket: string, balance: int}>  $negativeBalances
     * @param  list<array{transaction_group: string, asset_type: string, total: int}>  $unbalancedGroups
     * @param  array<string, int>  $conservation
     */
    public function __construct(
        public int $accountsChecked,
        public array $discrepancies,
        public array $negativeBalances,
        public array $unbalancedGroups,
        public array $conservation,
        public ?string $lastRunAt,
    ) {}

    public function healthy(): bool
    {
        foreach ($this->conservation as $total) {
            if ($total !== 0) {
                return false;
            }
        }

        return $this->discrepancies === []
            && $this->negativeBalances === []
            && $this->unbalancedGroups === [];
    }
}
