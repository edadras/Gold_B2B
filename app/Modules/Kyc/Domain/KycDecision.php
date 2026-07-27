<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain;

/**
 * The three buttons on the compliance review screen (docs §1.5).
 * Every one of them requires a written note.
 */
enum KycDecision: string
{
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case INFO_REQUIRED = 'INFO_REQUIRED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function resultingKycStatus(): KycStatus
    {
        return match ($this) {
            self::APPROVED => KycStatus::APPROVED,
            self::REJECTED => KycStatus::REJECTED,
            self::INFO_REQUIRED => KycStatus::INFO_REQUIRED,
        };
    }
}
