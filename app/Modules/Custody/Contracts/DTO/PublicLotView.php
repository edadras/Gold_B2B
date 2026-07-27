<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts\DTO;

/**
 * The payload behind a QR scan — docs/03-domain/02-gold-lot-assay.md §2.8.
 *
 * SECURITY: this object is served without authentication. It must never carry
 * the owner, any price, or any counterparty. The shape is asserted by
 * QrTokenServiceTest so a future field cannot leak one in by accident.
 */
final readonly class PublicLotView
{
    public function __construct(
        public string $lotCode,
        public string $metalType,
        public int $grossWeightMg,
        public int $purityX10,
        public int $fineWeightMg,
        public string $puritySource,
        public string $shape,
        public ?string $serialNumber,
        public ?string $hallmarkCode,
        public string $status,
        public string $custodyState,
        public ?string $assayCode,
        public ?string $laboratoryName,
        public ?string $assayMethod,
        public ?string $assayedAt,
        public ?string $certificateNo,
        public bool $certified,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lot_code' => $this->lotCode,
            'metal_type' => $this->metalType,
            'gross_weight_mg' => $this->grossWeightMg,
            'purity_x10' => $this->purityX10,
            'fine_weight_mg' => $this->fineWeightMg,
            'purity_source' => $this->puritySource,
            'shape' => $this->shape,
            'serial_number' => $this->serialNumber,
            'hallmark_code' => $this->hallmarkCode,
            'status' => $this->status,
            'custody_state' => $this->custodyState,
            'assay_code' => $this->assayCode,
            'laboratory_name' => $this->laboratoryName,
            'assay_method' => $this->assayMethod,
            'assayed_at' => $this->assayedAt,
            'certificate_no' => $this->certificateNo,
            'certified' => $this->certified,
        ];
    }
}
