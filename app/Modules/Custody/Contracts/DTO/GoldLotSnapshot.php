<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts\DTO;

use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\MetalType;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * Immutable read model of a gold lot handed to other modules.
 *
 * Deliberately not an Eloquent model: Settlement, Trading and Risk read lots
 * constantly and must never be able to mutate one (AGENT_BRIEF rule 7).
 */
final readonly class GoldLotSnapshot
{
    public function __construct(
        public int $id,
        public string $lotCode,
        public MetalType $metalType,
        public int $grossWeightMg,
        public int $purityX10,
        public int $fineWeightMg,
        public PuritySource $puritySource,
        public LotStatus $status,
        public OriginType $originType,
        public int $ownerOrganizationId,
        public CustodianType $custodianType,
        public int $custodianId,
        public ?int $vaultBoxId,
        public ?string $physicalLocation,
        public ?string $serialNumber,
        public ?int $currentAssayId,
        public int $generation,
        public string $createdAt,
    ) {}

    public function gross(): Weight
    {
        return Weight::fromMilligrams($this->grossWeightMg);
    }

    public function fine(): FineWeight
    {
        return FineWeight::fromMilligrams($this->fineWeightMg);
    }

    public function purity(): Purity
    {
        return Purity::fromScaled($this->purityX10);
    }

    /** Listable on the order book: certified purity, available, book-transferable. */
    public function isOrderBookEligible(): bool
    {
        return $this->puritySource->isTradableOnOrderBook()
            && $this->status->isAllocatable()
            && $this->custodianType->allowsBookTransfer();
    }

    public function isOwnedBy(int $organizationId): bool
    {
        return $this->ownerOrganizationId === $organizationId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lot_code' => $this->lotCode,
            'metal_type' => $this->metalType->value,
            'gross_weight_mg' => $this->grossWeightMg,
            'purity_x10' => $this->purityX10,
            'fine_weight_mg' => $this->fineWeightMg,
            'purity_source' => $this->puritySource->value,
            'status' => $this->status->value,
            'origin_type' => $this->originType->value,
            'owner_organization_id' => $this->ownerOrganizationId,
            'custodian_type' => $this->custodianType->value,
            'custodian_id' => $this->custodianId,
            'vault_box_id' => $this->vaultBoxId,
            'physical_location' => $this->physicalLocation,
            'serial_number' => $this->serialNumber,
            'current_assay_id' => $this->currentAssayId,
            'generation' => $this->generation,
            'created_at' => $this->createdAt,
        ];
    }
}
