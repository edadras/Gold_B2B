<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/**
 * Accepted security and its default haircut. docs/03-domain/11-risk-credit.md §11.6.
 */
enum CollateralType: string
{
    case GOLD_VAULT = 'GOLD_VAULT';
    case RIAL_DEPOSIT = 'RIAL_DEPOSIT';
    case BANK_GUARANTEE = 'BANK_GUARANTEE';
    case THIRD_PARTY_GUARANTEE = 'THIRD_PARTY_GUARANTEE';

    /** Acceptance factor in basis points: 90% is 9000. */
    public function defaultAcceptanceFactorBps(): int
    {
        return match ($this) {
            self::GOLD_VAULT => 9_000,
            self::RIAL_DEPOSIT => 10_000,
            self::BANK_GUARANTEE => 9_500,
            self::THIRD_PARTY_GUARANTEE => 5_000,
        };
    }

    /** Gold collateral is revalued on every price move; the rest is not. */
    public function isPriceSensitive(): bool
    {
        return $this === self::GOLD_VAULT;
    }

    public function label(): string
    {
        return match ($this) {
            self::GOLD_VAULT => 'طلای مسدودشده در خزانه',
            self::RIAL_DEPOSIT => 'سپرده ریالی نزد سامانه',
            self::BANK_GUARANTEE => 'ضمانت‌نامه بانکی',
            self::THIRD_PARTY_GUARANTEE => 'ضمانت شخص ثالث معتبر',
        };
    }
}
