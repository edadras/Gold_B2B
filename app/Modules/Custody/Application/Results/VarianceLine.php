<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

use App\Modules\Custody\Domain\Enums\VarianceClassification;

/** One reconciled line: what the books said, what the scale said. */
final readonly class VarianceLine
{
    public function __construct(
        public ?int $lotId,
        public ?string $lotCode,
        public ?string $serialNumber,
        public ?int $expectedGrossMg,
        public ?int $countedGrossMg,
        public int $differenceMg,
        public int $differenceBps,
        public VarianceClassification $classification,
        public ?string $expectedLocation = null,
        public ?string $countedLocation = null,
    ) {}

    public function isMisplaced(): bool
    {
        return $this->expectedLocation !== null
            && $this->countedLocation !== null
            && $this->expectedLocation !== $this->countedLocation;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lot_id' => $this->lotId,
            'lot_code' => $this->lotCode,
            'serial_number' => $this->serialNumber,
            'expected_gross_mg' => $this->expectedGrossMg,
            'counted_gross_mg' => $this->countedGrossMg,
            'difference_mg' => $this->differenceMg,
            'difference_bps' => $this->differenceBps,
            'classification' => $this->classification->value,
            'expected_location' => $this->expectedLocation,
            'counted_location' => $this->countedLocation,
            'misplaced' => $this->isMisplaced(),
        ];
    }
}
