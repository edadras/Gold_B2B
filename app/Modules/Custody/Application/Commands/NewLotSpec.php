<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\MetalType;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * Everything needed to materialise one gold_lots row.
 *
 * $fine is normally derived with formula F1; MERGE overrides it with the exact
 * sum of the input lots' fine weights so the operation cannot invent metal
 * through rounding.
 */
final readonly class NewLotSpec
{
    public function __construct(
        public int $ownerOrganizationId,
        public Weight $gross,
        public Purity $purity,
        public PuritySource $puritySource,
        public LotShape $shape,
        public OriginType $originType,
        public CustodianType $custodianType,
        public int $custodianId,
        public LotStatus $status,
        public ?FineWeight $fine = null,
        public ?int $vaultBoxId = null,
        public ?string $physicalLocation = null,
        public ?string $serialNumber = null,
        public ?string $hallmarkCode = null,
        public ?int $refinerId = null,
        public ?string $refinedAt = null,
        public ?int $currentAssayId = null,
        public int $generation = 1,
        public MetalType $metalType = MetalType::GOLD,
        public ?int $createdByUserId = null,
    ) {}

    public function fineWeight(): FineWeight
    {
        return $this->fine ?? FineWeight::calculate($this->gross, $this->purity);
    }

    public function withFine(FineWeight $fine): self
    {
        return new self(
            ownerOrganizationId: $this->ownerOrganizationId,
            gross: $this->gross,
            purity: $this->purity,
            puritySource: $this->puritySource,
            shape: $this->shape,
            originType: $this->originType,
            custodianType: $this->custodianType,
            custodianId: $this->custodianId,
            status: $this->status,
            fine: $fine,
            vaultBoxId: $this->vaultBoxId,
            physicalLocation: $this->physicalLocation,
            serialNumber: $this->serialNumber,
            hallmarkCode: $this->hallmarkCode,
            refinerId: $this->refinerId,
            refinedAt: $this->refinedAt,
            currentAssayId: $this->currentAssayId,
            generation: $this->generation,
            metalType: $this->metalType,
            createdByUserId: $this->createdByUserId,
        );
    }
}
