<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/** A lot is now physically held by the platform's vault. */
final readonly class LotEnteredVault
{
    public function __construct(
        public int $lotId,
        public string $lotCode,
        public int $vaultId,
        public ?string $locationCode,
        public int $ownerOrganizationId,
        public int $fineWeightMg,
        public int $operationId,
        public ?int $byUserId,
        public string $occurredAt,
    ) {}
}
