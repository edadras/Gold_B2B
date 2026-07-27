<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * The compliance officer's view of the KYC dossier.
 *
 * Kyc\Contracts\KycDirectory answers per-member yes/no questions but has no
 * queue read and no review write, so those live here.
 */
interface KycAdminPort
{
    /**
     * @param  list<string>  $statuses
     * @return list<KycQueueItem>
     */
    public function queue(array $statuses, int $limit = 100): array;

    public function countByStatus(string $status): int;

    public function dossier(int $organizationId): ?KycDossier;

    /**
     * Document metadata for one document, or null. Never returns file contents:
     * fetching those is a separate, audited action.
     *
     * @return array{id: int, organization_id: int, type: string, status: string, original_filename: ?string, file_hash: ?string, disk: ?string, storage_path: ?string, size_bytes: ?int}|null
     */
    public function document(int $documentId): ?array;

    /**
     * Record a decision. `$decision` is one of APPROVED, REJECTED,
     * INFO_REQUIRED; `$notes` is mandatory and already validated by the caller.
     *
     * @param  list<AutomatedCheck>  $checks
     * @return int id of the kyc_reviews row
     */
    public function recordDecision(
        int $organizationId,
        int $officerUserId,
        string $decision,
        string $notes,
        array $checks,
    ): int;
}
