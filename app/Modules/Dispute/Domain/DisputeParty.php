<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

enum DisputeParty: string
{
    case CLAIMANT = 'CLAIMANT';
    case RESPONDENT = 'RESPONDENT';
    case BOTH = 'BOTH';

    public function label(): string
    {
        return match ($this) {
            self::CLAIMANT => 'معترض',
            self::RESPONDENT => 'طرف مقابل',
            self::BOTH => 'هر دو طرف',
        };
    }
}
