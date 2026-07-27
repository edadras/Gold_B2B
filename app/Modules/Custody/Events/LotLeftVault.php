<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/** A lot was handed over at the counter and is no longer vault-held. */
final readonly class LotLeftVault
{
    public function __construct(
        public int $lotId,
        public string $lotCode,
        public int $vaultId,
        public int $receiverOrganizationId,
        public string $waybillNo,
        public int $fineWeightMg,
        public int $operationId,
        public ?int $byUserId,
        public string $occurredAt,
    ) {}
}
