<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * Why a ledger entry exists.
 *
 * The first block is the canonical table from docs/03-domain/03-ledger.md §3.4,
 * in document order. The second block covers the lifecycle steps the worked
 * examples (docs/11-appendix/03-worked-examples.md) post but §3.4 does not name
 * individually; they are flagged by isCanonical() so a doc-coverage test can
 * assert the §3.4 set exactly.
 */
enum EntryType: string
{
    // ── §3.4 canonical set ────────────────────────────────────────────────
    case OPENING_BALANCE = 'OPENING_BALANCE';
    case DEPOSIT_GOLD = 'DEPOSIT_GOLD';
    case WITHDRAW_GOLD = 'WITHDRAW_GOLD';
    case DEPOSIT_CASH = 'DEPOSIT_CASH';
    case WITHDRAW_CASH = 'WITHDRAW_CASH';
    case TRADE_BUY_GOLD = 'TRADE_BUY_GOLD';
    case TRADE_SELL_GOLD = 'TRADE_SELL_GOLD';
    case TRADE_BUY_CASH = 'TRADE_BUY_CASH';
    case TRADE_SELL_CASH = 'TRADE_SELL_CASH';
    case RESERVE = 'RESERVE';
    case RELEASE = 'RELEASE';
    case FEE_CHARGE = 'FEE_CHARGE';
    case FEE_INCOME = 'FEE_INCOME';
    case TAX_WITHHOLD = 'TAX_WITHHOLD';
    case ASSAY_ADJUSTMENT = 'ASSAY_ADJUSTMENT';
    case PROCESSING_LOSS = 'PROCESSING_LOSS';
    case ROUNDING = 'ROUNDING';
    case NETTING_SETTLE = 'NETTING_SETTLE';
    case DISPUTE_HOLD = 'DISPUTE_HOLD';
    case DISPUTE_RELEASE = 'DISPUTE_RELEASE';
    case PENALTY = 'PENALTY';
    case REVERSAL = 'REVERSAL';
    case MANUAL_ADJUSTMENT = 'MANUAL_ADJUSTMENT';

    // ── used by the worked examples, outside the §3.4 table ───────────────
    /** RESERVED → IN_SETTLEMENT once a trade produces a settlement (example 1, g4). */
    case SETTLEMENT_LOCK = 'SETTLEMENT_LOCK';
    /** IN_SETTLEMENT → AVAILABLE when a settlement is cancelled (example 6). */
    case SETTLEMENT_RELEASE = 'SETTLEMENT_RELEASE';
    /** Counter-leg of PENALTY: the injured party receives it (example 6, g12). */
    case PENALTY_RECEIVED = 'PENALTY_RECEIVED';
    /** Collateral seized on default (example 6, g13). */
    case COLLATERAL_SEIZE = 'COLLATERAL_SEIZE';
    /** Seized collateral handed to the injured party (example 6, g13). */
    case COLLATERAL_TRANSFER = 'COLLATERAL_TRANSFER';

    /**
     * The 23 types listed in the §3.4 table, in document order.
     *
     * @return array<int, self>
     */
    public static function canonical(): array
    {
        return [
            self::OPENING_BALANCE,
            self::DEPOSIT_GOLD,
            self::WITHDRAW_GOLD,
            self::DEPOSIT_CASH,
            self::WITHDRAW_CASH,
            self::TRADE_BUY_GOLD,
            self::TRADE_SELL_GOLD,
            self::TRADE_BUY_CASH,
            self::TRADE_SELL_CASH,
            self::RESERVE,
            self::RELEASE,
            self::FEE_CHARGE,
            self::FEE_INCOME,
            self::TAX_WITHHOLD,
            self::ASSAY_ADJUSTMENT,
            self::PROCESSING_LOSS,
            self::ROUNDING,
            self::NETTING_SETTLE,
            self::DISPUTE_HOLD,
            self::DISPUTE_RELEASE,
            self::PENALTY,
            self::REVERSAL,
            self::MANUAL_ADJUSTMENT,
        ];
    }

    public function isCanonical(): bool
    {
        return in_array($this, self::canonical(), true);
    }

    /**
     * Which asset classes this type may be posted against.
     *
     * @return array<int, AssetType>
     */
    public function assets(): array
    {
        $both = [AssetType::GOLD, AssetType::RIAL];

        return match ($this) {
            self::DEPOSIT_GOLD,
            self::WITHDRAW_GOLD,
            self::TRADE_BUY_GOLD,
            self::TRADE_SELL_GOLD,
            self::ASSAY_ADJUSTMENT,
            self::PROCESSING_LOSS,
            self::COLLATERAL_SEIZE,
            self::COLLATERAL_TRANSFER => [AssetType::GOLD],

            self::DEPOSIT_CASH,
            self::WITHDRAW_CASH,
            self::TRADE_BUY_CASH,
            self::TRADE_SELL_CASH,
            self::FEE_CHARGE,
            self::FEE_INCOME,
            self::TAX_WITHHOLD,
            self::PENALTY,
            self::PENALTY_RECEIVED => [AssetType::RIAL],

            default => $both,
        };
    }

    public function appliesTo(AssetType $asset): bool
    {
        return in_array($asset, $this->assets(), true);
    }

    /**
     * Required sign of the amount: +1 credit-only, -1 debit-only, null either.
     *
     * RESERVE / RELEASE / DISPUTE_HOLD / DISPUTE_RELEASE are listed with a
     * single sign in §3.4 because the table describes the AVAILABLE leg, but
     * each posts a balanced pair, so both signs are legal here.
     */
    public function requiredSign(): ?int
    {
        return match ($this) {
            self::OPENING_BALANCE,
            self::DEPOSIT_GOLD,
            self::DEPOSIT_CASH,
            self::TRADE_BUY_GOLD,
            self::TRADE_SELL_CASH,
            self::FEE_INCOME,
            self::PENALTY_RECEIVED,
            self::COLLATERAL_TRANSFER => 1,

            self::WITHDRAW_GOLD,
            self::WITHDRAW_CASH,
            self::TRADE_SELL_GOLD,
            self::TRADE_BUY_CASH,
            self::FEE_CHARGE,
            self::TAX_WITHHOLD,
            self::PROCESSING_LOSS,
            self::PENALTY,
            self::COLLATERAL_SEIZE => -1,

            default => null,
        };
    }

    /**
     * Types that may never be posted without a second approver
     * (docs/03-domain/03-ledger.md §3.4 — «تأیید دوگانه اجباری»).
     */
    public function requiresDualApproval(): bool
    {
        return $this === self::MANUAL_ADJUSTMENT || $this === self::REVERSAL;
    }

    /**
     * The system account that absorbs the other side when an operation has no
     * member counterparty. Keeps invariant I4 (Σ per asset == 0) true even for
     * deposits, withdrawals, fees and rounding dust.
     */
    public function systemCounterpart(AssetType $asset, int $sign): SystemAccountCode
    {
        return match ($this) {
            self::FEE_CHARGE, self::FEE_INCOME => SystemAccountCode::FEE_INCOME,
            self::ROUNDING => SystemAccountCode::ROUNDING_DIFFERENCE,
            self::PROCESSING_LOSS => SystemAccountCode::PROCESSING_LOSS,
            self::ASSAY_ADJUSTMENT => SystemAccountCode::ASSAY_VARIANCE,
            self::NETTING_SETTLE => SystemAccountCode::CLEARING,
            self::MANUAL_ADJUSTMENT, self::REVERSAL => SystemAccountCode::SUSPENSE,
            self::TAX_WITHHOLD => SystemAccountCode::EXTERNAL_CASH_OUT,
            default => $asset === AssetType::GOLD
                ? ($sign >= 0 ? SystemAccountCode::EXTERNAL_GOLD_IN : SystemAccountCode::EXTERNAL_GOLD_OUT)
                : ($sign >= 0 ? SystemAccountCode::EXTERNAL_CASH_IN : SystemAccountCode::EXTERNAL_CASH_OUT),
        };
    }
}
