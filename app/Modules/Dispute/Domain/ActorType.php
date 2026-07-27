<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

/**
 * Who performed a timeline action — `dispute_timeline.actor_type` in §13.5.
 *
 * SYSTEM is required by §2.14 rule 4: automatic transitions must be attributed
 * to the system, so a timeline never implies a person did something a cron job
 * did.
 */
enum ActorType: string
{
    case CLAIMANT = 'CLAIMANT';
    case RESPONDENT = 'RESPONDENT';
    case MEDIATOR = 'MEDIATOR';
    case SYSTEM = 'SYSTEM';

    public function label(): string
    {
        return match ($this) {
            self::CLAIMANT => 'معترض',
            self::RESPONDENT => 'طرف مقابل',
            self::MEDIATOR => 'میانجی',
            self::SYSTEM => 'سیستم',
        };
    }
}
