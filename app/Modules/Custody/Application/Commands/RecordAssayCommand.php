<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Enums\AssayMethod;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;

/** File a laboratory certificate against a lot. Also used for re-assays. */
final readonly class RecordAssayCommand
{
    public function __construct(
        public int $goldLotId,
        public int $laboratoryId,
        public string $certificateNo,
        public AssayMethod $method,
        public Weight $grossWeight,
        public Purity $purity,
        public string $assayedAt,
        public int $recordedByUserId,
        public ?string $validUntil = null,
        public ?int $documentId = null,
        public ?string $verifiedByLabAt = null,
        public ?string $reason = null,
    ) {}
}
