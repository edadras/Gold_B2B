<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts\DTO;

use App\Modules\Custody\Domain\Enums\AssayMethod;
use App\Modules\Custody\Domain\Enums\AssayStatus;
use App\Modules\Shared\ValueObjects\Purity;

/** Immutable read model of an assay certificate. */
final readonly class AssaySnapshot
{
    public function __construct(
        public int $id,
        public string $assayCode,
        public int $goldLotId,
        public string $certificateNo,
        public int $laboratoryId,
        public ?string $laboratoryName,
        public AssayMethod $method,
        public int $grossWeightMg,
        public int $purityX10,
        public int $fineWeightMg,
        public string $assayedAt,
        public ?string $validUntil,
        public AssayStatus $status,
        public ?int $supersededById,
        public ?string $verifiedByLabAt,
    ) {}

    public function purity(): Purity
    {
        return Purity::fromScaled($this->purityX10);
    }

    public function isAuthoritative(): bool
    {
        return $this->status->isAuthoritative();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'assay_code' => $this->assayCode,
            'gold_lot_id' => $this->goldLotId,
            'certificate_no' => $this->certificateNo,
            'laboratory_id' => $this->laboratoryId,
            'laboratory_name' => $this->laboratoryName,
            'method' => $this->method->value,
            'gross_weight_mg' => $this->grossWeightMg,
            'purity_x10' => $this->purityX10,
            'fine_weight_mg' => $this->fineWeightMg,
            'assayed_at' => $this->assayedAt,
            'valid_until' => $this->validUntil,
            'status' => $this->status->value,
            'superseded_by_id' => $this->supersededById,
            'verified_by_lab_at' => $this->verifiedByLabAt,
        ];
    }
}
