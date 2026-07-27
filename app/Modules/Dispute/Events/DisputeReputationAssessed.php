<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Events;

/**
 * The reputation consequences of a verdict, as an announcement rather than an
 * action (§13.8).
 *
 * This module does not write credit scores. It states what happened — who lost,
 * whether the claim was judged frivolous, whether the platform was at fault —
 * and Reputation decides what that is worth. Applying the −80 directly from
 * here would put a scoring policy inside a dispute workflow, and the two change
 * for entirely different reasons.
 *
 * `losingParty` is CLAIMANT, RESPONDENT, BOTH or null (nobody lost).
 */
final readonly class DisputeReputationAssessed
{
    public function __construct(
        public int $disputeId,
        public string $caseNumber,
        public string $decision,
        public ?string $losingParty,
        public ?int $losingOrgId,
        public int $claimantOrgId,
        public int $respondentOrgId,
        public bool $isFrivolousClaim,
        public bool $isPlatformFault,
    ) {}
}
