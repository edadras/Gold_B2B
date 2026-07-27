<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Contracts\DTO\GoldLotSnapshot;
use App\Modules\Custody\Contracts\DTO\LineageEdge;
use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Shared\Http\Support\Display;

/**
 * Assembles the `GET /lots/{id}/lineage` payload of
 * docs/05-api/02-endpoints.md §2.10.
 *
 * ADDED FOR THE HTTP LAYER. LineageService answers the graph question — which
 * lot ids are upstream and downstream of this one — and deliberately returns
 * nothing but edges. The documented response needs three joins on top of that:
 * each edge's lot code and weights, each operation's mass balance, and a
 * timestamp per hop. Doing that in a controller would be three queries in a
 * class that is not allowed one; doing it inside LineageService would drag
 * Eloquent into a class that is pure recursive SQL. So it lives here.
 *
 * Deliberately absent from the payload: the owner of any ancestor or
 * descendant. A merge input may well have belonged to another member, and the
 * genealogy of a bar is not a licence to learn who used to hold it.
 */
final class LineageViewService
{
    public function __construct(
        private readonly LineageService $lineage,
        private readonly GoldLotRepositoryInterface $lots,
    ) {}

    /**
     * @return array{
     *     lot: array{id: int, lot_code: string},
     *     ancestors: list<array<string, mixed>>,
     *     descendants: list<array<string, mixed>>,
     *     operations: list<array<string, mixed>>,
     * }
     */
    public function forLot(GoldLotSnapshot $lot): array
    {
        $ancestorEdges = $this->lineage->ancestorsOf($lot->id);
        $descendantEdges = $this->lineage->descendantsOf($lot->id);

        $relatedIds = array_values(array_unique(array_merge(
            array_map(static fn (LineageEdge $e): int => $e->parentLotId, $ancestorEdges),
            array_map(static fn (LineageEdge $e): int => $e->childLotId, $descendantEdges),
        )));

        $related = [];
        foreach ($this->lots->findMany($relatedIds) as $snapshot) {
            $related[$snapshot->id] = $snapshot;
        }

        $operationIds = array_values(array_unique(array_map(
            static fn (LineageEdge $e): int => $e->operationId,
            array_merge($ancestorEdges, $descendantEdges),
        )));

        $operations = $this->operationsById($operationIds);

        return [
            'lot' => ['id' => $lot->id, 'lot_code' => $lot->lotCode],
            'ancestors' => array_map(
                fn (LineageEdge $edge): array => $this->hop(
                    $edge->parentLotId,
                    $edge,
                    $related[$edge->parentLotId] ?? null,
                    $operations[$edge->operationId] ?? null,
                ),
                $ancestorEdges,
            ),
            'descendants' => array_map(
                fn (LineageEdge $edge): array => $this->hop(
                    $edge->childLotId,
                    $edge,
                    $related[$edge->childLotId] ?? null,
                    $operations[$edge->operationId] ?? null,
                ),
                $descendantEdges,
            ),
            'operations' => array_values(array_map(
                static fn (CustodyOperationModel $operation): array => [
                    'id' => (int) $operation->id,
                    'type' => $operation->operation_type->value,
                    'status' => $operation->status->value,
                    'occurred_at' => Display::iso($operation->executed_at ?? $operation->requested_at),
                    'input_fine_mg' => (int) $operation->input_fine_mg,
                    'output_fine_mg' => (int) $operation->output_fine_mg,
                    'loss_fine_mg' => (int) $operation->loss_fine_mg,
                ],
                $operations,
            )),
        ];
    }

    /**
     * One hop of the walk. A lot that has been purged from the read side
     * (which should never happen — lineage rows are append-only and CONSUMED
     * lots are kept precisely for this) degrades to ids rather than blowing up.
     *
     * @return array<string, mixed>
     */
    private function hop(int $lotId, LineageEdge $edge, ?GoldLotSnapshot $lot, ?CustodyOperationModel $operation): array
    {
        return [
            'lot_id' => $lotId,
            'lot_code' => $lot?->lotCode,
            'operation' => $edge->operation->value,
            'operation_id' => $edge->operationId,
            'gross_weight_mg' => $lot?->grossWeightMg,
            'purity_x10' => $lot?->purityX10,
            'fine_weight_mg' => $lot?->fineWeightMg,
            'depth' => $edge->depth,
            'occurred_at' => Display::iso($operation?->executed_at ?? $operation?->requested_at),
        ];
    }

    /**
     * @param  list<int>  $operationIds
     * @return array<int, CustodyOperationModel> keyed by id, ascending
     */
    private function operationsById(array $operationIds): array
    {
        if ($operationIds === []) {
            return [];
        }

        $byId = [];

        foreach (CustodyOperationModel::query()->whereIn('id', $operationIds)->orderBy('id')->get() as $operation) {
            $byId[(int) $operation->id] = $operation;
        }

        return $byId;
    }
}
