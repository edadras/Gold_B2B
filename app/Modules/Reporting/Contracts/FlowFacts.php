<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use App\Modules\Shared\Support\IntMath;
use JsonSerializable;

/**
 * The raw movements behind a flow report, in the shape §15.7 prescribes:
 *
 *     Opening Balance
 *     + Inflows      (به تفکیک منبع)
 *     − Outflows     (به تفکیک مقصد)
 *     ± Adjustments  (با ذکر دلیل هر مورد)
 *     = Closing Balance
 *
 * Each bucket is a label → amount map, because the document's own example
 * breaks purchases down into Order Book, OTC and RFQ and itemises adjustments
 * by reason. A single total would satisfy the arithmetic and lose the report.
 *
 * Inflows and outflows are unsigned magnitudes; adjustments are signed, since
 * a re-assay can go either way.
 */
final readonly class FlowFacts implements JsonSerializable
{
    /**
     * @param  array<string, int>  $inflows  unsigned
     * @param  array<string, int>  $outflows  unsigned
     * @param  array<string, int>  $adjustments  signed
     */
    public function __construct(
        public int $opening,
        public array $inflows = [],
        public array $outflows = [],
        public array $adjustments = [],
    ) {}

    public static function empty(int $opening = 0): self
    {
        return new self($opening);
    }

    public function totalInflows(): int
    {
        return IntMath::sum(array_values($this->inflows));
    }

    public function totalOutflows(): int
    {
        return IntMath::sum(array_values($this->outflows));
    }

    public function totalAdjustments(): int
    {
        return IntMath::sum(array_values($this->adjustments));
    }

    /**
     * The closing balance the report's own lines add up to.
     *
     * Deliberately named "computed": it is the report's claim, which is then
     * checked against an independent read of the ledger.
     */
    public function computedClosing(): int
    {
        return IntMath::add(
            IntMath::add($this->opening, $this->totalInflows()),
            IntMath::sub($this->totalAdjustments(), $this->totalOutflows()),
        );
    }

    public function inflow(string $label): int
    {
        return $this->inflows[$label] ?? 0;
    }

    public function outflow(string $label): int
    {
        return $this->outflows[$label] ?? 0;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'opening' => $this->opening,
            'inflows' => $this->inflows,
            'outflows' => $this->outflows,
            'adjustments' => $this->adjustments,
            'total_inflows' => $this->totalInflows(),
            'total_outflows' => $this->totalOutflows(),
            'total_adjustments' => $this->totalAdjustments(),
            'closing' => $this->computedClosing(),
        ];
    }
}
