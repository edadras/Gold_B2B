<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\LineSet;
use InvalidArgumentException;
use JsonSerializable;

/**
 * One row of a voucher, mirroring `journal_lines` (§9.2).
 *
 * A line lives in exactly one set: a RIAL line carries only rial, a GOLD line
 * only milligrams. That restriction is what keeps each column independently
 * balanced (see LineSet).
 */
final readonly class VoucherLine implements JsonSerializable
{
    public function __construct(
        public AccountCode $account,
        public LineSet $set,
        public int $debitRial = 0,
        public int $creditRial = 0,
        public int $debitFineMg = 0,
        public int $creditFineMg = 0,
        public ?int $counterpartyOrgId = null,
        public ?string $description = null,
    ) {
        foreach ([$this->debitRial, $this->creditRial, $this->debitFineMg, $this->creditFineMg] as $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException('Journal line amounts are unsigned; use the opposite column');
            }
        }

        if ($this->debitRial > 0 && $this->creditRial > 0) {
            throw new InvalidArgumentException('A line cannot be both a rial debit and a rial credit');
        }

        if ($this->debitFineMg > 0 && $this->creditFineMg > 0) {
            throw new InvalidArgumentException('A line cannot be both a gold debit and a gold credit');
        }

        if ($this->set === LineSet::RIAL && ($this->debitFineMg > 0 || $this->creditFineMg > 0)) {
            throw new InvalidArgumentException('A RIAL line may not carry a gold quantity');
        }

        if ($this->set === LineSet::GOLD) {
            if ($this->debitRial > 0 || $this->creditRial > 0) {
                throw new InvalidArgumentException('A GOLD line may not carry a rial amount');
            }

            if (! $this->account->carriesGoldQuantity()) {
                throw new InvalidArgumentException(
                    "Account {$this->account->value} does not carry a gold quantity"
                );
            }
        }
    }

    public static function debitRial(
        AccountCode $account,
        int $amount,
        ?string $description = null,
        ?int $counterpartyOrgId = null,
    ): self {
        return new self(
            account: $account,
            set: LineSet::RIAL,
            debitRial: $amount,
            counterpartyOrgId: $counterpartyOrgId,
            description: $description,
        );
    }

    public static function creditRial(
        AccountCode $account,
        int $amount,
        ?string $description = null,
        ?int $counterpartyOrgId = null,
    ): self {
        return new self(
            account: $account,
            set: LineSet::RIAL,
            creditRial: $amount,
            counterpartyOrgId: $counterpartyOrgId,
            description: $description,
        );
    }

    public static function debitGold(
        AccountCode $account,
        int $fineMg,
        ?string $description = null,
        ?int $counterpartyOrgId = null,
    ): self {
        return new self(
            account: $account,
            set: LineSet::GOLD,
            debitFineMg: $fineMg,
            counterpartyOrgId: $counterpartyOrgId,
            description: $description,
        );
    }

    public static function creditGold(
        AccountCode $account,
        int $fineMg,
        ?string $description = null,
        ?int $counterpartyOrgId = null,
    ): self {
        return new self(
            account: $account,
            set: LineSet::GOLD,
            creditFineMg: $fineMg,
            counterpartyOrgId: $counterpartyOrgId,
            description: $description,
        );
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
            'line_set' => $this->set->value,
            'debit_rial' => $this->debitRial,
            'credit_rial' => $this->creditRial,
            'debit_fine_mg' => $this->debitFineMg,
            'credit_fine_mg' => $this->creditFineMg,
            'counterparty' => $this->counterpartyOrgId,
        ];
    }
}
