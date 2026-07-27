<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts\DTO;

use App\Modules\Custody\Domain\Enums\LineageOperation;

/** One parent -> child edge of the genealogy graph, with its distance. */
final readonly class LineageEdge
{
    public function __construct(
        public int $parentLotId,
        public int $childLotId,
        public LineageOperation $operation,
        public int $operationId,
        public int $depth,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'parent_lot_id' => $this->parentLotId,
            'child_lot_id' => $this->childLotId,
            'operation' => $this->operation->value,
            'operation_id' => $this->operationId,
            'depth' => $this->depth,
        ];
    }
}
