<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Domain\BalanceSide;
use JsonSerializable;

/**
 * One account's turnover and closing balance — a row of the report in §9.4.
 */
final readonly class TrialBalanceRow implements JsonSerializable
{
    public function __construct(
        public AccountCode $account,
        public int $debitRial,
        public int $creditRial,
        public int $debitFineMg,
        public int $creditFineMg,
    ) {}

    public function type(): AccountType
    {
        return $this->account->type();
    }

    /** Signed net movement in rial: positive means a debit balance. */
    public function netRial(): int
    {
        return $this->debitRial - $this->creditRial;
    }

    public function netFineMg(): int
    {
        return $this->debitFineMg - $this->creditFineMg;
    }

    /** Absolute closing balance, with the side it sits on reported separately. */
    public function balanceRial(): int
    {
        return abs($this->netRial());
    }

    public function side(): BalanceSide
    {
        return $this->netRial() >= 0 ? BalanceSide::DEBIT : BalanceSide::CREDIT;
    }

    public function isEmpty(): bool
    {
        return $this->debitRial === 0
            && $this->creditRial === 0
            && $this->debitFineMg === 0
            && $this->creditFineMg === 0;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'account_code' => $this->account->value,
            'account_name' => $this->account->label(),
            'account_type' => $this->account->type()->value,
            'debit_rial' => $this->debitRial,
            'credit_rial' => $this->creditRial,
            'debit_fine_mg' => $this->debitFineMg,
            'credit_fine_mg' => $this->creditFineMg,
            'balance_rial' => $this->balanceRial(),
            'balance_side' => $this->side()->value,
            'net_fine_mg' => $this->netFineMg(),
        ];
    }
}
