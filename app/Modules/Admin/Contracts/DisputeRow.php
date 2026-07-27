<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class DisputeRow
{
    public function __construct(
        public int $id,
        public string $caseNumber,
        public string $disputeType,
        public string $status,
        public string $priority,
        public int $claimantOrgId,
        public int $respondentOrgId,
        public ?string $claimantName,
        public ?string $respondentName,
        public int $claimGoldMg,
        public int $claimRial,
        public ?int $tradeId,
        public ?int $settlementId,
        public ?int $mediatorUserId,
        public ?string $replyDeadlineAt,
        public ?string $negotiationDeadlineAt,
        public string $openedAt,
    ) {}
}
