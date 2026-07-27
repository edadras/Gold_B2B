<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/**
 * docs/03-domain/11-risk-credit.md §11.1 / §11.2.
 *
 * A new member always starts at MEDIUM, never LOW.
 */
enum RiskLevel: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    /** Score bands of §11.2. */
    public static function fromCreditScore(int $score): self
    {
        return match (true) {
            $score >= 800 => self::LOW,
            $score >= 500 => self::MEDIUM,
            $score >= 200 => self::HIGH,
            default => self::CRITICAL,
        };
    }

    public static function forNewMember(): self
    {
        return self::MEDIUM;
    }

    public function canTrade(): bool
    {
        return $this !== self::CRITICAL;
    }

    /**
     * Default ceilings of §11.1, used to seed trading_limits.
     *
     * @return array{
     *     max_order_mg:int, max_daily_volume_mg:int, max_open_orders:int,
     *     max_open_exposure_mg:int, max_open_exposure_rial:int,
     *     unsecured_credit_mg:int, allowed_settlement_types:list<string>,
     *     max_settlement_days:int
     * }
     */
    public function defaults(): array
    {
        return match ($this) {
            self::LOW => [
                'max_order_mg' => 10_000_000,          // 10 kg
                'max_daily_volume_mg' => 50_000_000,   // 50 kg
                'max_open_orders' => 100,
                'max_open_exposure_mg' => 50_000_000,
                'max_open_exposure_rial' => 4_000_000_000_000,
                'unsecured_credit_mg' => 5_000_000,    // 5 kg
                'allowed_settlement_types' => ['T0', 'T1', 'ON_ACCOUNT'],
                'max_settlement_days' => 7,
            ],
            self::MEDIUM => [
                'max_order_mg' => 2_000_000,           // 2 kg
                'max_daily_volume_mg' => 10_000_000,   // 10 kg
                'max_open_orders' => 20,
                'max_open_exposure_mg' => 10_000_000,
                'max_open_exposure_rial' => 800_000_000_000,
                'unsecured_credit_mg' => 500_000,      // 500 g
                'allowed_settlement_types' => ['T0', 'T1'],
                'max_settlement_days' => 1,
            ],
            self::HIGH => [
                'max_order_mg' => 500_000,             // 500 g
                'max_daily_volume_mg' => 2_000_000,    // 2 kg
                'max_open_orders' => 5,
                'max_open_exposure_mg' => 2_000_000,
                'max_open_exposure_rial' => 160_000_000_000,
                'unsecured_credit_mg' => 0,
                'allowed_settlement_types' => ['T0'],
                'max_settlement_days' => 0,
            ],
            self::CRITICAL => [
                'max_order_mg' => 0,
                'max_daily_volume_mg' => 0,
                'max_open_orders' => 0,
                'max_open_exposure_mg' => 0,
                'max_open_exposure_rial' => 0,
                'unsecured_credit_mg' => 0,
                'allowed_settlement_types' => [],
                'max_settlement_days' => 0,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'کم‌ریسک',
            self::MEDIUM => 'ریسک متوسط',
            self::HIGH => 'پرریسک',
            self::CRITICAL => 'بحرانی',
        };
    }
}
