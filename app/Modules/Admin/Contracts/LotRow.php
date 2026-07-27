<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class LotRow
{
    public function __construct(
        public int $id,
        public string $lotCode,
        public ?int $ownerOrganizationId,
        public ?string $ownerName,
        public string $status,
        public int $grossWeightMg,
        public int $purityX10,
        public int $fineWeightMg,
        public string $custodianType,
        public ?int $vaultBoxId,
        public ?string $physicalLocation,
    ) {}
}
