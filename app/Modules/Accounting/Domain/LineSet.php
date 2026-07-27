<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * Which of the two balanced sets a journal line belongs to.
 *
 * docs/03-domain/09-accounting.md §9.3 records the decision explicitly: a
 * voucher for a gold trade cannot balance as one mixed set, because line ۳
 * (۱۱۰۳ موجودی ریالی) has no gold quantity to offset the weight debited to
 * ۱۱۱۰. Rather than invent a fictitious weight on a cash account, the voucher
 * carries two independent sets:
 *
 *   RIAL — the money movement, Σ debit_rial = Σ credit_rial
 *   GOLD — the weight movement against a MEMO account (۹۱۰۱),
 *          Σ debit_fine_mg = Σ credit_fine_mg
 *
 * Each column therefore balances on its own, and the entry-wide invariant of
 * §9.2 holds as a consequence rather than by coincidence.
 */
enum LineSet: string
{
    case RIAL = 'RIAL';
    case GOLD = 'GOLD';

    /** The unit this set is denominated in. */
    public function unit(): string
    {
        return match ($this) {
            self::RIAL => 'IRR',
            self::GOLD => 'mg',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RIAL => 'ثبت ریالی',
            self::GOLD => 'ثبت طلایی',
        };
    }
}
