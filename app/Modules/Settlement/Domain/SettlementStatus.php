<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * The settlement state machine — docs/11-appendix/02-state-machines.md §2.4,
 * transcribed exactly, including the reference implementation in §2.1.
 *
 * Fourteen states. The happy path is CREATED → ASSETS_LOCKED →
 * PAYMENT_PENDING → PAYMENT_DECLARED → PAYMENT_CONFIRMED → GOLD_TRANSFERRING →
 * SETTLED → COMPLETED; NETTING_QUEUE is the alternative route out of
 * ASSETS_LOCKED, and OVERDUE / DEFAULTED / CANCELLED / DISPUTED / REVERSED are
 * the exception paths.
 *
 * Note the deliberate asymmetry called out in appendix §2.14 rule 3: COMPLETED
 * is financially final but still reachable by DISPUTED and REVERSED, because a
 * fraudulent settlement discovered a week later must still be correctable.
 * CANCELLED and REVERSED are the only truly terminal states.
 */
enum SettlementStatus: string
{
    case CREATED = 'CREATED';
    case ASSETS_LOCKED = 'ASSETS_LOCKED';
    case PAYMENT_PENDING = 'PAYMENT_PENDING';
    case PAYMENT_DECLARED = 'PAYMENT_DECLARED';
    case PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED';
    case GOLD_TRANSFERRING = 'GOLD_TRANSFERRING';
    case SETTLED = 'SETTLED';
    case COMPLETED = 'COMPLETED';
    case OVERDUE = 'OVERDUE';
    case DEFAULTED = 'DEFAULTED';
    case CANCELLED = 'CANCELLED';
    case DISPUTED = 'DISPUTED';
    case REVERSED = 'REVERSED';
    case NETTING_QUEUE = 'NETTING_QUEUE';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::CREATED => [
                self::ASSETS_LOCKED, self::CANCELLED,
            ],
            self::ASSETS_LOCKED => [
                self::PAYMENT_PENDING, self::NETTING_QUEUE,
                self::CANCELLED, self::DISPUTED,
            ],
            self::PAYMENT_PENDING => [
                self::PAYMENT_DECLARED, self::OVERDUE,
                self::CANCELLED, self::DISPUTED,
            ],
            self::PAYMENT_DECLARED => [
                self::PAYMENT_CONFIRMED, self::OVERDUE,
                self::DISPUTED,
            ],
            self::PAYMENT_CONFIRMED => [
                self::GOLD_TRANSFERRING, self::DISPUTED,
            ],
            self::GOLD_TRANSFERRING => [
                self::SETTLED, self::DISPUTED,
            ],
            self::SETTLED => [
                self::COMPLETED, self::DISPUTED, self::REVERSED,
            ],
            self::COMPLETED => [
                self::DISPUTED, self::REVERSED,
            ],
            self::OVERDUE => [
                self::PAYMENT_DECLARED, self::DEFAULTED,
                self::CANCELLED, self::DISPUTED,
            ],
            self::DEFAULTED => [
                self::SETTLED, self::CANCELLED, self::DISPUTED,
            ],
            self::NETTING_QUEUE => [
                self::SETTLED, self::PAYMENT_PENDING, self::CANCELLED,
            ],
            self::DISPUTED => [
                self::SETTLED, self::REVERSED, self::CANCELLED,
            ],
            self::CANCELLED, self::REVERSED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the settlement still represents an outstanding obligation.
     *
     * Used by Identity's CLOSED precondition ("no open settlement") and by the
     * overdue sweep, which only looks at open rows.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [
            self::COMPLETED, self::CANCELLED, self::REVERSED,
        ], true);
    }

    /** States where assets are held in IN_SETTLEMENT and the deadline still bites. */
    public function isAwaitingPayment(): bool
    {
        return in_array($this, [
            self::ASSETS_LOCKED, self::PAYMENT_PENDING,
            self::PAYMENT_DECLARED, self::OVERDUE,
        ], true);
    }

    /** The obligation has been discharged in the ledger. */
    public function isSettled(): bool
    {
        return in_array($this, [self::SETTLED, self::COMPLETED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'ایجادشده',
            self::ASSETS_LOCKED => 'دارایی قفل شد',
            self::PAYMENT_PENDING => 'در انتظار پرداخت',
            self::PAYMENT_DECLARED => 'پرداخت اعلام شد',
            self::PAYMENT_CONFIRMED => 'پرداخت تأیید شد',
            self::GOLD_TRANSFERRING => 'در حال انتقال طلا',
            self::SETTLED => 'تسویه شد',
            self::COMPLETED => 'تکمیل‌شده',
            self::OVERDUE => 'سررسید گذشته',
            self::DEFAULTED => 'نکول',
            self::CANCELLED => 'لغوشده',
            self::DISPUTED => 'دارای اختلاف',
            self::REVERSED => 'برگشت‌خورده',
            self::NETTING_QUEUE => 'در صف تهاتر',
        };
    }
}
