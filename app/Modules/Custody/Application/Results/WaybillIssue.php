<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

/**
 * The exit permit and its one-time code.
 *
 * $oneTimeCode is the ONLY time the plaintext exists: the database stores a
 * SHA-256 hash. Hand it straight to the notification channel and never log it.
 */
final readonly class WaybillIssue
{
    /** @param list<int> $lotIds */
    public function __construct(
        public int $waybillId,
        public string $waybillNo,
        public int $operationId,
        public int $vaultId,
        public int $ownerOrganizationId,
        public array $lotIds,
        public string $oneTimeCode,
        public string $expiresAt,
        public string $qrToken,
    ) {}
}
