<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/** docs/03-domain/11-risk-credit.md §11.8. */
enum LimitIncreaseStatus: string
{
    case AUTO_REJECTED = 'AUTO_REJECTED';
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case APPROVED = 'APPROVED';
    case APPROVED_WITH_COLLATERAL = 'APPROVED_WITH_COLLATERAL';
    case REJECTED = 'REJECTED';

    public function isDecided(): bool
    {
        return $this !== self::PENDING_REVIEW;
    }

    public function grantsIncrease(): bool
    {
        return $this === self::APPROVED || $this === self::APPROVED_WITH_COLLATERAL;
    }
}
