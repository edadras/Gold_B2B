<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Events;

/**
 * A case was filed. Fired after commit, so a notification can never announce a
 * dispute that was rolled back.
 *
 * Carries the disputed figures because §13.4 requires an immediate Push + SMS
 * to the respondent stating what is being claimed and how long they have.
 */
final readonly class DisputeOpened
{
    public function __construct(
        public int $disputeId,
        public string $caseNumber,
        public string $disputeType,
        public int $claimantOrgId,
        public int $respondentOrgId,
        public ?int $tradeId,
        public int $claimGoldMg,
        public int $claimRial,
        public string $replyDeadlineAt,
        public bool $fundsHeld,
    ) {}
}
