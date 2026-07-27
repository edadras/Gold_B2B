<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

use App\Modules\Shared\ValueObjects\FineWeight;

/**
 * Outcome of a melt.
 *
 * $fineLossMg is the refining loss the Ledger posts as PROCESSING_LOSS. Any
 * further difference discovered when the output is finally assayed becomes a
 * separate REFINING_VARIANCE entry driven by AssayAdjusted.
 */
final readonly class MeltResult
{
    /**
     * @param  list<int>  $inputLotIds
     * @param  list<int>  $outputLotIds
     * @param  list<string>  $outputLotCodes
     */
    public function __construct(
        public array $inputLotIds,
        public array $outputLotIds,
        public array $outputLotCodes,
        public int $ownerOrganizationId,
        public ?int $refinerId,
        public int $inputGrossMg,
        public int $outputGrossMg,
        public int $inputFineMg,
        public int $outputFineMg,
        public int $grossLossMg,
        public int $fineLossMg,
        public int $operationId,
    ) {}

    public function fineLoss(): FineWeight
    {
        return FineWeight::fromMilligrams($this->fineLossMg);
    }

    public function conserves(): bool
    {
        return $this->outputFineMg + $this->fineLossMg === $this->inputFineMg
            && $this->outputGrossMg + $this->grossLossMg === $this->inputGrossMg;
    }
}
