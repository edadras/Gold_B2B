<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * The life of one cross-settlement agreement — docs/03-domain/05-settlement.md
 * §5.7, «نیازمند: توافق صریح دو طرف».
 *
 *   PROPOSED -> AGREED | REJECTED
 *   AGREED   -> EXECUTED | REJECTED
 *   EXECUTED / REJECTED are final.
 *
 * PROPOSED already carries the proposer's own agreement; AGREED means both
 * sides have said yes and nothing else is outstanding. Only AGREED may execute,
 * which is the whole point of having states at all here: crossing asset classes
 * is not something either member may impose on the other.
 */
enum CrossSettlementStatus: string
{
    case PROPOSED = 'PROPOSED';
    case AGREED = 'AGREED';
    case EXECUTED = 'EXECUTED';
    case REJECTED = 'REJECTED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PROPOSED => [self::AGREED, self::REJECTED],
            self::AGREED => [self::EXECUTED, self::REJECTED],
            self::EXECUTED, self::REJECTED => [],
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

    /** Still competing for the same settlements' locked assets. */
    public function isOpen(): bool
    {
        return $this === self::PROPOSED || $this === self::AGREED;
    }

    public function label(): string
    {
        return match ($this) {
            self::PROPOSED => 'پیشنهادشده',
            self::AGREED => 'مورد توافق',
            self::EXECUTED => 'اجراشده',
            self::REJECTED => 'ردشده',
        };
    }
}
