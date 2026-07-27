<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * Everything the review page shows about one member's dossier.
 *
 * Documents arrive as metadata only — filename, type, status, hash. The file
 * itself is fetched through a separate, audited read (§1.11: «هر مشاهده داده
 * حساس در Audit ثبت شود»).
 */
final readonly class KycDossier
{
    /**
     * @param  list<array{id: int, type: string, status: string, original_filename: ?string, file_hash: ?string, size_bytes: ?int, uploaded_at: ?string}>  $documents
     * @param  list<array{id: int, bank_name: ?string, account_holder_name: ?string, status: string, is_primary: bool}>  $bankAccounts
     * @param  list<array{id: int, license_no: ?string, status: string, expires_at: ?string}>  $licenses
     * @param  list<AutomatedCheck>  $automatedChecks
     * @param  list<array{id: int, decision: string, notes: ?string, reviewer_user_id: ?int, reviewed_at: ?string}>  $reviewHistory
     */
    public function __construct(
        public int $profileId,
        public int $organizationId,
        public string $organizationName,
        public string $organizationType,
        public string $organizationStatus,
        public string $status,
        public string $riskLevel,
        public ?string $submittedAt,
        public ?string $lastDecisionNote,
        public array $documents,
        public array $bankAccounts,
        public array $licenses,
        public array $automatedChecks,
        public array $reviewHistory,
    ) {}
}
