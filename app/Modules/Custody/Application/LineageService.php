<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Contracts\DTO\LineageEdge;
use App\Modules\Custody\Domain\Enums\LineageOperation;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * Genealogy queries — docs/03-domain/02-gold-lot-assay.md §2.7.
 *
 * Uses MariaDB 10.11 / MySQL 8 WITH RECURSIVE with a hard depth cap so a
 * corrupted graph can never spin the database. lot_lineage also carries a
 * CHECK against self-parenting, and the unique (parent, child) key stops the
 * same edge being written twice.
 */
final class LineageService
{
    public const DEFAULT_MAX_DEPTH = 50;

    /**
     * Everything this lot came from, nearest ancestor first.
     *
     * @return list<LineageEdge>
     */
    public function ancestorsOf(int $lotId, ?int $maxDepth = null): array
    {
        $depth = $maxDepth ?? $this->maxDepth();

        $sql = <<<'SQL'
            WITH RECURSIVE ancestors AS (
                SELECT parent_lot_id, child_lot_id, operation, operation_id, 1 AS depth
                FROM lot_lineage
                WHERE child_lot_id = ?

                UNION ALL

                SELECT l.parent_lot_id, l.child_lot_id, l.operation, l.operation_id, a.depth + 1
                FROM lot_lineage l
                JOIN ancestors a ON l.child_lot_id = a.parent_lot_id
                WHERE a.depth < ?
            )
            SELECT parent_lot_id, child_lot_id, operation, operation_id, depth
            FROM ancestors
            ORDER BY depth, parent_lot_id
            SQL;

        return $this->toEdges(DB::select($sql, [$lotId, $depth]));
    }

    /**
     * Everything that came out of this lot, nearest descendant first.
     *
     * @return list<LineageEdge>
     */
    public function descendantsOf(int $lotId, ?int $maxDepth = null): array
    {
        $depth = $maxDepth ?? $this->maxDepth();

        $sql = <<<'SQL'
            WITH RECURSIVE descendants AS (
                SELECT parent_lot_id, child_lot_id, operation, operation_id, 1 AS depth
                FROM lot_lineage
                WHERE parent_lot_id = ?

                UNION ALL

                SELECT l.parent_lot_id, l.child_lot_id, l.operation, l.operation_id, d.depth + 1
                FROM lot_lineage l
                JOIN descendants d ON l.parent_lot_id = d.child_lot_id
                WHERE d.depth < ?
            )
            SELECT parent_lot_id, child_lot_id, operation, operation_id, depth
            FROM descendants
            ORDER BY depth, child_lot_id
            SQL;

        return $this->toEdges(DB::select($sql, [$lotId, $depth]));
    }

    /** @return list<int> distinct ancestor lot ids, nearest first */
    public function ancestorIdsOf(int $lotId, ?int $maxDepth = null): array
    {
        $ids = array_map(static fn (LineageEdge $e): int => $e->parentLotId, $this->ancestorsOf($lotId, $maxDepth));

        return array_values(array_unique($ids));
    }

    /** @return list<int> distinct descendant lot ids, nearest first */
    public function descendantIdsOf(int $lotId, ?int $maxDepth = null): array
    {
        $ids = array_map(static fn (LineageEdge $e): int => $e->childLotId, $this->descendantsOf($lotId, $maxDepth));

        return array_values(array_unique($ids));
    }

    /** The oldest known origins of a lot — the leaves of the ancestor walk. */
    public function rootAncestorIdsOf(int $lotId, ?int $maxDepth = null): array
    {
        $edges = $this->ancestorsOf($lotId, $maxDepth);
        $children = array_map(static fn (LineageEdge $e): int => $e->childLotId, $edges);

        $roots = [];
        foreach ($edges as $edge) {
            if (! in_array($edge->parentLotId, $children, true)) {
                $roots[] = $edge->parentLotId;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param  list<object>  $rows
     * @return list<LineageEdge>
     */
    private function toEdges(array $rows): array
    {
        return array_map(
            static fn (object $row): LineageEdge => new LineageEdge(
                parentLotId: (int) $row->parent_lot_id,
                childLotId: (int) $row->child_lot_id,
                operation: LineageOperation::from((string) $row->operation),
                operationId: (int) $row->operation_id,
                depth: (int) $row->depth,
            ),
            $rows,
        );
    }

    private function maxDepth(): int
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return self::DEFAULT_MAX_DEPTH;
        }

        return (int) $container->make('config')
            ->get('goldb2b.custody.lineage.max_depth', self::DEFAULT_MAX_DEPTH);
    }
}
