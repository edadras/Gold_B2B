<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Events;

/**
 * The verdict has been applied: holds released, any award transferred.
 *
 * The §13.7 sample code fires this at the end of executeDecision(), and so does
 * ResolutionService — after the transaction commits.
 */
final readonly class DisputeResolved
{
    public function __construct(
        public int $disputeId,
        public string $caseNumber,
        public string $decision,
        public int $claimantOrgId,
        public int $respondentOrgId,
        public int $awardedGoldMg,
        public int $awardedRial,
        public ?int $tradeId,
        public ?int $goldLotId,
    ) {}
}
