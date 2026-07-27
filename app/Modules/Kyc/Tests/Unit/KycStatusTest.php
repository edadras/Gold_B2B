<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Tests\Unit;

use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Kyc\Domain\DocumentStatus;
use App\Modules\Kyc\Domain\DocumentType;
use App\Modules\Kyc\Domain\KycDecision;
use App\Modules\Kyc\Domain\KycStatus;
use PHPUnit\Framework\TestCase;

/**
 * The KycProfile machine of docs/11-appendix/02-state-machines.md §2.12,
 * asserted over every ordered pair.
 */
final class KycStatusTest extends TestCase
{
    private const EXPECTED = [
        'DRAFT' => ['SUBMITTED'],
        'SUBMITTED' => ['IN_REVIEW', 'DRAFT'],
        'IN_REVIEW' => ['APPROVED', 'INFO_REQUIRED', 'REJECTED'],
        'INFO_REQUIRED' => ['SUBMITTED'],
        'APPROVED' => ['IN_REVIEW'],
        'REJECTED' => [],
    ];

    public function test_every_ordered_pair_matches_the_specification(): void
    {
        foreach (KycStatus::cases() as $from) {
            $allowed = self::EXPECTED[$from->value]
                ?? self::fail("No expectation declared for {$from->value}");

            foreach (KycStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed, true),
                    $from->canTransitionTo($to),
                    "{$from->value} -> {$to->value}",
                );
            }
        }
    }

    public function test_rejected_is_the_only_final_state(): void
    {
        foreach (KycStatus::cases() as $status) {
            $this->assertSame(
                $status === KycStatus::REJECTED,
                $status->isFinal(),
                "{$status->value} finality",
            );
        }
    }

    public function test_an_approved_dossier_can_be_reopened_for_periodic_review(): void
    {
        $this->assertTrue(KycStatus::APPROVED->canTransitionTo(KycStatus::IN_REVIEW));
        $this->assertFalse(KycStatus::APPROVED->canTransitionTo(KycStatus::REJECTED));
    }

    public function test_editability_and_queue_membership(): void
    {
        $this->assertTrue(KycStatus::DRAFT->isEditableByMember());
        $this->assertTrue(KycStatus::INFO_REQUIRED->isEditableByMember());
        $this->assertFalse(KycStatus::IN_REVIEW->isEditableByMember());

        $this->assertTrue(KycStatus::SUBMITTED->isAwaitingOfficer());
        $this->assertTrue(KycStatus::IN_REVIEW->isAwaitingOfficer());
        $this->assertFalse(KycStatus::APPROVED->isAwaitingOfficer());
    }

    public function test_decisions_map_onto_reachable_statuses(): void
    {
        foreach (KycDecision::cases() as $decision) {
            $this->assertTrue(
                KycStatus::IN_REVIEW->canTransitionTo($decision->resultingKycStatus()),
                "{$decision->value} must be reachable from IN_REVIEW",
            );
        }
    }

    public function test_required_documents_match_the_specification(): void
    {
        $individual = array_map(
            static fn (DocumentType $t): string => $t->value,
            DocumentType::requiredFor(OrganizationType::INDIVIDUAL),
        );

        $this->assertSame([
            'NATIONAL_CARD_FRONT',
            'NATIONAL_CARD_BACK',
            'BUSINESS_LICENSE',
            'SELFIE_WITH_NATIONAL_CARD',
        ], $individual);

        $legal = array_map(
            static fn (DocumentType $t): string => $t->value,
            DocumentType::requiredFor(OrganizationType::LEGAL_ENTITY),
        );

        $this->assertContains('OFFICIAL_GAZETTE_ESTABLISHMENT', $legal);
        $this->assertContains('SIGNATURE_CERTIFICATE', $legal);
        $this->assertContains('ECONOMIC_CODE', $legal);

        // Optional documents are optional for both.
        $this->assertFalse(DocumentType::UNION_MEMBERSHIP_CERTIFICATE->isRequiredFor(OrganizationType::INDIVIDUAL));
        $this->assertFalse(DocumentType::PREMISES_DEED_OR_LEASE->isRequiredFor(OrganizationType::INDIVIDUAL));
    }

    public function test_document_status_transitions(): void
    {
        $this->assertTrue(DocumentStatus::PENDING->canTransitionTo(DocumentStatus::VERIFIED));
        $this->assertTrue(DocumentStatus::VERIFIED->canTransitionTo(DocumentStatus::EXPIRED));
        $this->assertFalse(DocumentStatus::SUPERSEDED->canTransitionTo(DocumentStatus::VERIFIED));

        $this->assertTrue(DocumentStatus::PENDING->countsAsProvided());
        $this->assertTrue(DocumentStatus::VERIFIED->countsAsProvided());
        $this->assertFalse(DocumentStatus::REJECTED->countsAsProvided());
        $this->assertFalse(DocumentStatus::SUPERSEDED->countsAsProvided());
    }
}
