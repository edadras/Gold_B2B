<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use JsonSerializable;

/**
 * The trial balance of docs/03-domain/09-accounting.md §9.4.
 *
 * `isBalanced` is reported, never assumed. A trial balance that does not foot
 * is the single most important signal this module can produce, and hiding it
 * behind an exception would remove the operator's ability to look at the rows
 * and see which account is wrong.
 */
final readonly class TrialBalance implements JsonSerializable
{
    /** @param array<int, TrialBalanceRow> $rows */
    public function __construct(
        public int $organizationId,
        public string $fromDate,
        public string $toDate,
        public array $rows,
        public int $totalDebitRial,
        public int $totalCreditRial,
        public int $totalDebitFineMg,
        public int $totalCreditFineMg,
    ) {}

    public function isBalanced(): bool
    {
        return $this->totalDebitRial === $this->totalCreditRial
            && $this->totalDebitFineMg === $this->totalCreditFineMg;
    }

    public function rialDifference(): int
    {
        return $this->totalDebitRial - $this->totalCreditRial;
    }

    public function goldDifference(): int
    {
        return $this->totalDebitFineMg - $this->totalCreditFineMg;
    }

    public function row(string $accountCode): ?TrialBalanceRow
    {
        foreach ($this->rows as $row) {
            if ($row->account->value === $accountCode) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'from' => $this->fromDate,
            'to' => $this->toDate,
            'rows' => array_map(static fn (TrialBalanceRow $r): array => $r->jsonSerialize(), $this->rows),
            'total_debit_rial' => $this->totalDebitRial,
            'total_credit_rial' => $this->totalCreditRial,
            'total_debit_fine_mg' => $this->totalDebitFineMg,
            'total_credit_fine_mg' => $this->totalCreditFineMg,
            'is_balanced' => $this->isBalanced(),
        ];
    }
}
