<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class VaultRow
{
    public function __construct(
        public int $id,
        public string $vaultCode,
        public string $name,
        public string $status,
        public ?int $capacityFineMg,
        public int $storedFineMg,
        public int $lotCount,
        public ?string $coverageExpiresAt,
    ) {}
}
