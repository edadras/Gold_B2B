<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests\Feature;

use App\Modules\Dispute\Application\DisputeService;
use App\Modules\Dispute\Application\EvidenceService;
use App\Modules\Dispute\Contracts\OpenDisputeCommand;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\DisputeType;
use App\Modules\Dispute\Domain\EvidenceType;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Dispute\Tests\DisputeTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use PHPUnit\Framework\Attributes\Test;

final class EvidenceServiceTest extends DisputeTestCase
{
    private const CLAIMANT = 184;

    private const RESPONDENT = 209;

    private EvidenceService $evidence;

    private DisputeService $disputes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evidence = $this->app->make(EvidenceService::class);
        $this->disputes = $this->app->make(DisputeService::class);
    }

    #[Test]
    public function either_party_may_file_evidence_while_the_case_is_open(): void
    {
        $dispute = $this->openDispute();

        $fromClaimant = $this->evidence->submit(
            $dispute,
            self::CLAIMANT,
            41,
            EvidenceType::ASSAY_REPORT,
            'گواهی آزمایشگاه مستقل',
            fileHash: str_repeat('a', 64),
        );

        $fromRespondent = $this->evidence->submit(
            $dispute,
            self::RESPONDENT,
            77,
            EvidenceType::DOCUMENT,
            'گواهی اولیه',
        );

        self::assertSame('ASSAY_REPORT', $fromClaimant->evidence_type);
        self::assertSame(str_repeat('a', 64), $fromClaimant->file_hash);
        self::assertSame('DOCUMENT', $fromRespondent->evidence_type);
    }

    #[Test]
    public function an_outsider_cannot_file_evidence(): void
    {
        $dispute = $this->openDispute();

        $this->expectException(OperationNotPermittedException::class);

        $this->evidence->submit($dispute, 999, 1, EvidenceType::PHOTO, 'عکس');
    }

    #[Test]
    public function a_member_cannot_submit_a_system_log(): void
    {
        $dispute = $this->openDispute();

        $this->expectException(OperationNotPermittedException::class);

        $this->evidence->submit($dispute, self::CLAIMANT, 41, EvidenceType::SYSTEM_LOG, 'لاگ');
    }

    #[Test]
    public function a_malformed_file_hash_is_rejected(): void
    {
        $dispute = $this->openDispute();

        $this->expectException(\InvalidArgumentException::class);

        $this->evidence->submit(
            $dispute,
            self::CLAIMANT,
            41,
            EvidenceType::PHOTO,
            'عکس قطعه',
            fileHash: 'not-a-sha256',
        );
    }

    #[Test]
    public function filing_the_requested_evidence_returns_the_case_to_the_mediator(): void
    {
        $dispute = $this->openDispute();
        $this->disputes->escalateToMediation($dispute, 3);

        $this->evidence->requestMore($dispute, 3, 'رسید ترازو لازم است');
        self::assertSame(DisputeStatus::AWAITING_EVIDENCE->value, $dispute->status);

        $this->evidence->submit($dispute, self::CLAIMANT, 41, EvidenceType::PHOTO, 'رسید ترازو');

        self::assertSame(
            DisputeStatus::UNDER_MEDIATION->value,
            (string) DisputeModel::query()->whereKey($dispute->id)->value('status'),
        );
    }

    #[Test]
    public function a_reassay_can_be_ordered_for_a_purity_dispute_and_its_result_resumes_mediation(): void
    {
        $dispute = $this->openDispute();
        $this->disputes->escalateToMediation($dispute, 3);

        $this->evidence->requestThirdPartyReassay($dispute, 3);
        self::assertSame(DisputeStatus::AWAITING_REASSAY->value, $dispute->status);

        $this->evidence->recordReassayResult($dispute, 3, 9_850, laboratoryId: 7);

        self::assertSame(DisputeStatus::UNDER_MEDIATION->value, $dispute->status);

        $log = $dispute->evidences()
            ->where('evidence_type', EvidenceType::SYSTEM_LOG->value)
            ->first();

        self::assertNotNull($log);
        self::assertStringContainsString('9850', (string) $log->description);
    }

    #[Test]
    public function a_reassay_cannot_settle_a_payment_dispute(): void
    {
        $dispute = $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::PAYMENT_NOT_RECEIVED,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 41,
            claimDescription: 'وجه دریافت نشد',
            respondentOrgId: self::RESPONDENT,
            claimRial: 1_000_000_000,
        ));

        $this->disputes->escalateToMediation($dispute, 3);

        $this->expectException(OperationNotPermittedException::class);

        $this->evidence->requestThirdPartyReassay($dispute, 3);
    }

    private function openDispute(): DisputeModel
    {
        $this->documentedTrade();

        return $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::PURITY_MISMATCH,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 41,
            claimDescription: 'عیار تحویلی ۹۸۵ است نه ۹۹۵',
            tradeId: 88231,
            actualPurityX10k: 9_850,
        ));
    }
}
