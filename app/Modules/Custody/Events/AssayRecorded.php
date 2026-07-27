<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/** A certificate was filed against a lot. */
final readonly class AssayRecorded
{
    public function __construct(
        public int $lotId,
        public int $assayId,
        public string $assayCode,
        public int $purityX10,
        public int $grossWeightMg,
        public int $fineWeightMg,
        public int $laboratoryId,
        public string $certificateNo,
        public string $method,
        public string $puritySource,
        public ?int $supersededAssayId,
        public int $recordedByUserId,
        public string $occurredAt,
    ) {}
}
