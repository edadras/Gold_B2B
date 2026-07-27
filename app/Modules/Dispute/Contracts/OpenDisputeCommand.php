<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Contracts;

use App\Modules\Dispute\Domain\DisputeType;
use InvalidArgumentException;

/**
 * Everything needed to open a case.
 *
 * The claim figures are optional: for a purity or weight dispute the disputed
 * quantity is derived from the trade and the alleged purity, and a
 * claimant-supplied number would just be a second opinion the system would have
 * to reconcile. For the categories where nothing can be derived — a missing
 * payment, say — `claimRial` is how the claimant states the amount.
 */
final readonly class OpenDisputeCommand
{
    public function __construct(
        public DisputeType $type,
        public int $claimantOrgId,
        public int $openedByUserId,
        public string $claimDescription,
        public ?int $tradeId = null,
        public ?int $settlementId = null,
        public ?int $goldLotId = null,
        /** Respondent, when the case is not tied to a trade. */
        public ?int $respondentOrgId = null,
        /** Alleged real purity ×10,000, for PURITY_MISMATCH. */
        public ?int $actualPurityX10k = null,
        /** Alleged real fine weight in mg, for WEIGHT_MISMATCH. */
        public ?int $actualFineMg = null,
        /** Claimed rial amount, for the categories with no derivable figure. */
        public int $claimRial = 0,
        /** Overrides the trade's execution price when valuing a shortfall. */
        public ?int $pricePerFineGram = null,
    ) {
        if ($this->claimantOrgId <= 0) {
            throw new InvalidArgumentException('A dispute needs a claimant organisation');
        }

        if (trim($this->claimDescription) === '') {
            throw new InvalidArgumentException('A dispute needs a description of the claim');
        }

        if ($this->claimRial < 0) {
            throw new InvalidArgumentException('A claimed amount cannot be negative');
        }

        if ($this->tradeId === null && $this->respondentOrgId === null) {
            throw new InvalidArgumentException(
                'A dispute without a trade must name the respondent explicitly'
            );
        }

        if ($this->respondentOrgId !== null && $this->respondentOrgId === $this->claimantOrgId) {
            throw new InvalidArgumentException('An organisation cannot dispute with itself');
        }
    }
}
