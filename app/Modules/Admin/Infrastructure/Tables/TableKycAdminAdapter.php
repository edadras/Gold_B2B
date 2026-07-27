<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\AutomatedCheck;
use App\Modules\Admin\Contracts\KycAdminPort;
use App\Modules\Admin\Contracts\KycDossier;
use App\Modules\Admin\Contracts\KycQueueItem;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The KYC queue and dossier, read straight off Kyc's tables.
 *
 * Kyc publishes `KycDirectory`, which answers "is this member approved?" and
 * little else; a review queue and a dossier view are outside it. Identity, by
 * contrast, does publish what is needed — the identifier verdicts come from
 * `IdentityDirectory::organizationIdentityChecks()` rather than from a second
 * copy of the validation rules.
 *
 * Nothing here decrypts an identity column. Documents are described by
 * metadata; the bytes are fetched by a separate, audited action.
 */
final class TableKycAdminAdapter implements KycAdminPort
{
    public function __construct(private readonly IdentityDirectory $directory) {}

    /** @return list<KycQueueItem> */
    public function queue(array $statuses, int $limit = 100): array
    {
        if (! Schema::hasTable('kyc_profiles') || ! Schema::hasTable('organizations')) {
            return [];
        }

        $query = DB::table('kyc_profiles as p')
            ->join('organizations as o', 'o.id', '=', 'p.organization_id')
            ->select([
                'p.id as profile_id', 'p.organization_id', 'p.status', 'p.submitted_at',
                'p.submission_count', 'o.display_name', 'o.status as org_status', 'o.risk_level',
            ]);

        if ($statuses !== []) {
            $query->whereIn('p.status', $statuses);
        }

        // Oldest submission first: a compliance queue that is not FIFO quietly
        // becomes a queue where awkward dossiers are never picked up.
        $rows = $query->orderByRaw('p.submitted_at IS NULL, p.submitted_at ASC')
            ->limit($limit)
            ->get();

        $counts = $this->documentCounts($rows->pluck('organization_id')->all());

        return array_map(fn (object $row): KycQueueItem => new KycQueueItem(
            profileId: (int) $row->profile_id,
            organizationId: (int) $row->organization_id,
            organizationName: (string) $row->display_name,
            status: (string) $row->status,
            organizationStatus: (string) $row->org_status,
            riskLevel: (string) $row->risk_level,
            submittedAt: $row->submitted_at === null ? null : (string) $row->submitted_at,
            submissionCount: (int) $row->submission_count,
            documentCount: $counts[(int) $row->organization_id] ?? 0,
        ), $rows->all());
    }

    public function countByStatus(string $status): int
    {
        if (! Schema::hasTable('kyc_profiles')) {
            return 0;
        }

        return (int) DB::table('kyc_profiles')->where('status', $status)->count();
    }

    public function dossier(int $organizationId): ?KycDossier
    {
        if (! Schema::hasTable('kyc_profiles') || ! Schema::hasTable('organizations')) {
            return null;
        }

        $profile = DB::table('kyc_profiles')->where('organization_id', $organizationId)->first();
        $organization = DB::table('organizations')->where('id', $organizationId)->first();

        if ($profile === null || $organization === null) {
            return null;
        }

        return new KycDossier(
            profileId: (int) $profile->id,
            organizationId: $organizationId,
            organizationName: (string) $organization->display_name,
            organizationType: (string) $organization->type,
            organizationStatus: (string) $organization->status,
            status: (string) $profile->status,
            riskLevel: (string) $organization->risk_level,
            submittedAt: $profile->submitted_at === null ? null : (string) $profile->submitted_at,
            lastDecisionNote: $profile->last_decision_note === null
                ? null
                : (string) $profile->last_decision_note,
            documents: $this->documents($organizationId),
            bankAccounts: $this->bankAccounts($organizationId),
            licenses: $this->licenses($organizationId),
            automatedChecks: $this->automatedChecks($organizationId),
            reviewHistory: $this->reviewHistory($organizationId),
        );
    }

    /** @return array{id: int, organization_id: int, type: string, status: string, original_filename: ?string, file_hash: ?string, disk: ?string, storage_path: ?string, size_bytes: ?int}|null */
    public function document(int $documentId): ?array
    {
        if (! Schema::hasTable('documents')) {
            return null;
        }

        $row = DB::table('documents')->where('id', $documentId)->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'organization_id' => (int) $row->organization_id,
            'type' => (string) $row->type,
            'status' => (string) $row->status,
            'original_filename' => $row->original_filename === null ? null : (string) $row->original_filename,
            'file_hash' => $row->file_hash === null ? null : (string) $row->file_hash,
            'disk' => $row->disk === null ? null : (string) $row->disk,
            'storage_path' => $row->storage_path === null ? null : (string) $row->storage_path,
            'size_bytes' => $row->size_bytes === null ? null : (int) $row->size_bytes,
        ];
    }

    /**
     * Writes the `kyc_reviews` row and moves the profile.
     *
     * The organisation's own status transition is Identity's business and is
     * driven by the caller through `OrganizationLifecycle`, so this method
     * touches Kyc's tables only.
     *
     * @param  list<AutomatedCheck>  $checks
     */
    public function recordDecision(
        int $organizationId,
        int $officerUserId,
        string $decision,
        string $notes,
        array $checks,
    ): int {
        if (! Schema::hasTable('kyc_profiles') || ! Schema::hasTable('kyc_reviews')) {
            throw new OperationNotPermittedException('جداول KYC در دسترس نیستند.');
        }

        if (trim($notes) === '') {
            throw new OperationNotPermittedException('ثبت تصمیم بدون یادداشت مجاز نیست.');
        }

        $profile = DB::table('kyc_profiles')->where('organization_id', $organizationId)->first();

        if ($profile === null) {
            throw new OperationNotPermittedException('پرونده KYC برای این سازمان وجود ندارد.');
        }

        $profileStatus = match ($decision) {
            'APPROVED' => 'APPROVED',
            'REJECTED' => 'REJECTED',
            'INFO_REQUIRED' => 'INFO_REQUIRED',
            default => throw new OperationNotPermittedException("تصمیم نامعتبر: {$decision}"),
        };

        $serialised = array_map(static fn (AutomatedCheck $c): array => [
            'key' => $c->key,
            'label' => $c->label,
            'passed' => $c->passed,
            'detail' => $c->detail,
        ], $checks);

        return (int) DB::transaction(function () use (
            $profile, $organizationId, $officerUserId, $decision, $profileStatus, $notes, $serialised,
        ): int {
            $update = [
                'status' => $profileStatus,
                'last_decision_note' => mb_substr($notes, 0, 255),
                'updated_at' => now(),
            ];

            if ($decision === 'APPROVED') {
                $update['approved_at'] = now();
                $update['approved_by_user_id'] = $officerUserId;
            }

            if ($decision === 'REJECTED') {
                $update['rejected_at'] = now();
            }

            DB::table('kyc_profiles')->where('id', $profile->id)->update($update);

            return (int) DB::table('kyc_reviews')->insertGetId([
                'organization_id' => $organizationId,
                'kyc_profile_id' => (int) $profile->id,
                'reviewer_user_id' => $officerUserId,
                'decision' => $decision,
                'notes' => mb_substr($notes, 0, 1000),
                'automated_checks' => json_encode($serialised, JSON_UNESCAPED_UNICODE),
                'missing_items' => null,
                'reviewed_at' => now(),
            ]);
        });
    }

    /**
     * The automated-check panel of the review page.
     *
     * Identity owns the verdicts on national id / legal id / registration
     * number, and it publishes them, so they are read rather than recomputed.
     * The remaining checks are completeness questions about the dossier itself.
     *
     * @return list<AutomatedCheck>
     */
    private function automatedChecks(int $organizationId): array
    {
        $checks = [];

        $identity = $this->directory->organizationIdentityChecks($organizationId);

        if ($identity !== null) {
            $checks[] = new AutomatedCheck(
                key: 'identity.national_id_valid',
                label: 'صحت شناسه ملی',
                passed: $identity->nationalIdValid,
                detail: $this->describeVerdict($identity->nationalIdValid),
            );

            $checks[] = new AutomatedCheck(
                key: 'identity.legal_id_valid',
                label: 'صحت شناسه حقوقی',
                passed: $identity->legalIdValid,
                detail: $this->describeVerdict($identity->legalIdValid),
            );

            // Inverted deliberately: a duplicate is a failure, so `passed` is
            // the negation. Presenting "duplicate: true" in a green row is how
            // a reviewer approves a shell company.
            $checks[] = new AutomatedCheck(
                key: 'identity.national_id_unique',
                label: 'شناسه ملی تکراری نیست',
                passed: ! $identity->duplicateNationalId,
                detail: $identity->duplicateNationalId
                    ? 'شناسه ملی روی سازمان دیگری هم ثبت شده'
                    : 'تکراری نیست',
            );

            $checks[] = new AutomatedCheck(
                key: 'identity.legal_id_unique',
                label: 'شناسه حقوقی تکراری نیست',
                passed: ! $identity->duplicateLegalId,
                detail: $identity->duplicateLegalId
                    ? 'شناسه حقوقی روی سازمان دیگری هم ثبت شده'
                    : 'تکراری نیست',
            );
        }

        if (Schema::hasTable('documents')) {
            $pending = (int) DB::table('documents')
                ->where('organization_id', $organizationId)
                ->where('status', 'PENDING')
                ->count();

            $rejected = (int) DB::table('documents')
                ->where('organization_id', $organizationId)
                ->where('status', 'REJECTED')
                ->count();

            $checks[] = new AutomatedCheck(
                key: 'documents.none_rejected',
                label: 'هیچ مدرکی ردشده نیست',
                passed: $rejected === 0,
                detail: $rejected === 0 ? 'بدون مدرک ردشده' : "{$rejected} مدرک ردشده",
            );

            $checks[] = new AutomatedCheck(
                key: 'documents.none_pending',
                label: 'همه مدارک بررسی شده‌اند',
                passed: $pending === 0,
                detail: $pending === 0 ? 'بدون مدرک در انتظار' : "{$pending} مدرک در انتظار بررسی",
            );
        }

        if (Schema::hasTable('bank_accounts')) {
            $verified = (int) DB::table('bank_accounts')
                ->where('organization_id', $organizationId)
                ->where('status', 'VERIFIED')
                ->count();

            $checks[] = new AutomatedCheck(
                key: 'bank.verified_account',
                label: 'حساب بانکی تأییدشده دارد',
                passed: $verified > 0,
                detail: $verified > 0 ? "{$verified} حساب تأییدشده" : 'حساب بانکی تأییدشده ندارد',
            );
        }

        if (Schema::hasTable('business_licenses')) {
            $active = DB::table('business_licenses')
                ->where('organization_id', $organizationId)
                ->where('status', 'ACTIVE')
                ->max('expires_at');

            $checks[] = new AutomatedCheck(
                key: 'license.active',
                label: 'جواز کسب معتبر دارد',
                passed: $active !== null,
                detail: $active === null ? 'جواز معتبری ثبت نشده' : 'اعتبار تا '.(string) $active,
            );
        }

        return $checks;
    }

    private function describeVerdict(mixed $value): string
    {
        if ($value === true) {
            return 'تأیید شد';
        }

        if ($value === false) {
            return 'رد شد';
        }

        return 'قابل ارزیابی نیست';
    }

    /** @return list<array{id: int, type: string, status: string, original_filename: ?string, file_hash: ?string, size_bytes: ?int, uploaded_at: ?string}> */
    private function documents(int $organizationId): array
    {
        if (! Schema::hasTable('documents')) {
            return [];
        }

        $rows = DB::table('documents')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get(['id', 'type', 'status', 'original_filename', 'file_hash', 'size_bytes', 'created_at']);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'status' => (string) $row->status,
            'original_filename' => $row->original_filename === null ? null : (string) $row->original_filename,
            'file_hash' => $row->file_hash === null ? null : (string) $row->file_hash,
            'size_bytes' => $row->size_bytes === null ? null : (int) $row->size_bytes,
            'uploaded_at' => $row->created_at === null ? null : (string) $row->created_at,
        ], $rows->all());
    }

    /** @return list<array{id: int, bank_name: ?string, account_holder_name: ?string, status: string, is_primary: bool}> */
    private function bankAccounts(int $organizationId): array
    {
        if (! Schema::hasTable('bank_accounts')) {
            return [];
        }

        // iban_enc is deliberately absent from the projection: a compliance
        // officer reviewing a dossier has no need of the account number, and
        // what is never selected can never leak.
        $rows = DB::table('bank_accounts')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get(['id', 'bank_name', 'account_holder_name', 'status', 'is_primary']);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'bank_name' => $row->bank_name === null ? null : (string) $row->bank_name,
            'account_holder_name' => $row->account_holder_name === null
                ? null
                : (string) $row->account_holder_name,
            'status' => (string) $row->status,
            'is_primary' => (bool) $row->is_primary,
        ], $rows->all());
    }

    /** @return list<array{id: int, license_no: ?string, status: string, expires_at: ?string}> */
    private function licenses(int $organizationId): array
    {
        if (! Schema::hasTable('business_licenses')) {
            return [];
        }

        $columns = Schema::getColumnListing('business_licenses');
        $numberColumn = in_array('license_no', $columns, true)
            ? 'license_no'
            : (in_array('license_number', $columns, true) ? 'license_number' : null);

        $rows = DB::table('business_licenses')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get();

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'license_no' => $numberColumn === null || ! isset($row->{$numberColumn})
                ? null
                : (string) $row->{$numberColumn},
            'status' => isset($row->status) ? (string) $row->status : 'UNKNOWN',
            'expires_at' => isset($row->expires_at) && $row->expires_at !== null
                ? (string) $row->expires_at
                : null,
        ], $rows->all());
    }

    /** @return list<array{id: int, decision: string, notes: ?string, reviewer_user_id: ?int, reviewed_at: ?string}> */
    private function reviewHistory(int $organizationId): array
    {
        if (! Schema::hasTable('kyc_reviews')) {
            return [];
        }

        $rows = DB::table('kyc_reviews')
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'decision', 'notes', 'reviewer_user_id', 'reviewed_at']);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'decision' => (string) $row->decision,
            'notes' => $row->notes === null ? null : (string) $row->notes,
            'reviewer_user_id' => $row->reviewer_user_id === null ? null : (int) $row->reviewer_user_id,
            'reviewed_at' => $row->reviewed_at === null ? null : (string) $row->reviewed_at,
        ], $rows->all());
    }

    /**
     * @param  list<mixed>  $organizationIds
     * @return array<int, int>
     */
    private function documentCounts(array $organizationIds): array
    {
        if ($organizationIds === [] || ! Schema::hasTable('documents')) {
            return [];
        }

        $rows = DB::table('documents')
            ->whereIn('organization_id', $organizationIds)
            ->selectRaw('organization_id, COUNT(*) AS total')
            ->groupBy('organization_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->organization_id] = (int) $row->total;
        }

        return $out;
    }
}
