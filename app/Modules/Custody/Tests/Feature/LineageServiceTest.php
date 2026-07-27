<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\Commands\MergeLotsCommand;
use App\Modules\Custody\Application\Commands\SplitLotCommand;
use App\Modules\Custody\Application\Commands\SplitPart;
use App\Modules\Custody\Application\LineageService;
use App\Modules\Custody\Application\MergeService;
use App\Modules\Custody\Application\SplitService;
use App\Modules\Custody\Contracts\DTO\LineageEdge;
use App\Modules\Custody\Domain\Enums\LineageOperation;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\Weight;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Genealogy — docs/03-domain/02-gold-lot-assay.md §2.7.
 *
 * The tree built here is three generations deep:
 *
 *     root  (1,000,000 mg)
 *       ├── a   ──split──►  a1, a2
 *       └── b
 *
 * so `a` has one ancestor, `a1` has two, and the root has four descendants.
 */
#[Group('custody')]
#[Group('lineage')]
final class LineageServiceTest extends CustodyTestCase
{
    private function lineage(): LineageService
    {
        return app(LineageService::class);
    }

    /** @return array{root: int, a: int, b: int, a1: int, a2: int} */
    private function buildThreeGenerationTree(): array
    {
        $split = app(SplitService::class);

        $root = $this->makeLot(grossMg: 1_000_000, purityX10: 9_950);

        // Generation 2
        $first = $split->split(new SplitLotCommand(
            parentLotId: (int) $root->id,
            parts: [SplitPart::byGross(Weight::fromMilligrams(400_000))],
            requestedByUserId: 1,
        ));

        [$a, $b] = $first->childLotIds;

        // Generation 3
        $second = $split->split(new SplitLotCommand(
            parentLotId: $a,
            parts: [SplitPart::byGross(Weight::fromMilligrams(150_000))],
            requestedByUserId: 1,
        ));

        [$a1, $a2] = $second->childLotIds;

        return ['root' => (int) $root->id, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2];
    }

    #[Test]
    public function it_walks_every_ancestor_of_a_third_generation_lot(): void
    {
        $tree = $this->buildThreeGenerationTree();

        $ancestors = $this->lineage()->ancestorsOf($tree['a1']);

        $this->assertCount(2, $ancestors);

        $this->assertSame($tree['a'], $ancestors[0]->parentLotId);
        $this->assertSame($tree['a1'], $ancestors[0]->childLotId);
        $this->assertSame(1, $ancestors[0]->depth);
        $this->assertSame(LineageOperation::SPLIT, $ancestors[0]->operation);

        $this->assertSame($tree['root'], $ancestors[1]->parentLotId);
        $this->assertSame($tree['a'], $ancestors[1]->childLotId);
        $this->assertSame(2, $ancestors[1]->depth);

        $this->assertSame([$tree['a'], $tree['root']], $this->lineage()->ancestorIdsOf($tree['a1']));
        $this->assertSame([$tree['root']], $this->lineage()->rootAncestorIdsOf($tree['a1']));
    }

    #[Test]
    public function it_walks_every_descendant_of_the_root(): void
    {
        $tree = $this->buildThreeGenerationTree();

        $descendants = $this->lineage()->descendantsOf($tree['root']);

        $this->assertCount(4, $descendants, 'a, b, a1, a2');

        $ids = $this->lineage()->descendantIdsOf($tree['root']);
        sort($ids);

        $expected = [$tree['a'], $tree['b'], $tree['a1'], $tree['a2']];
        sort($expected);

        $this->assertSame($expected, $ids);

        $depthByChild = [];
        foreach ($descendants as $edge) {
            $depthByChild[$edge->childLotId] = $edge->depth;
        }

        $this->assertSame(1, $depthByChild[$tree['a']]);
        $this->assertSame(1, $depthByChild[$tree['b']]);
        $this->assertSame(2, $depthByChild[$tree['a1']]);
        $this->assertSame(2, $depthByChild[$tree['a2']]);
    }

    #[Test]
    public function a_generation_two_lot_sees_only_the_root_above_it(): void
    {
        $tree = $this->buildThreeGenerationTree();

        $this->assertSame([$tree['root']], $this->lineage()->ancestorIdsOf($tree['a']));
        $this->assertSame([$tree['root']], $this->lineage()->ancestorIdsOf($tree['b']));
    }

    #[Test]
    public function a_leaf_has_no_descendants_and_a_root_has_no_ancestors(): void
    {
        $tree = $this->buildThreeGenerationTree();

        $this->assertSame([], $this->lineage()->descendantsOf($tree['a1']));
        $this->assertSame([], $this->lineage()->ancestorsOf($tree['root']));
    }

    #[Test]
    public function the_depth_cap_bounds_the_recursion(): void
    {
        $tree = $this->buildThreeGenerationTree();

        $shallow = $this->lineage()->ancestorsOf($tree['a1'], maxDepth: 1);

        $this->assertCount(1, $shallow);
        $this->assertSame(1, $shallow[0]->depth);
    }

    #[Test]
    public function a_merge_gives_a_lot_several_parents(): void
    {
        $split = app(SplitService::class);
        $merge = app(MergeService::class);

        $root = $this->makeLot(grossMg: 300_000, purityX10: 7_500);

        $splitResult = $split->split(new SplitLotCommand(
            parentLotId: (int) $root->id,
            parts: [SplitPart::byGross(Weight::fromMilligrams(100_000))],
            requestedByUserId: 1,
        ));

        $mergeResult = $merge->merge(new MergeLotsCommand(
            lotIds: $splitResult->childLotIds,
            requestedByUserId: 1,
        ));

        $ancestors = $this->lineage()->ancestorsOf($mergeResult->newLotId);

        // Two MERGE edges at depth 1, then the shared SPLIT parent at depth 2.
        $depthOne = array_values(array_filter($ancestors, static fn (LineageEdge $e): bool => $e->depth === 1));
        $this->assertCount(2, $depthOne);

        foreach ($depthOne as $edge) {
            $this->assertSame(LineageOperation::MERGE, $edge->operation);
        }

        $this->assertContains((int) $root->id, $this->lineage()->ancestorIdsOf($mergeResult->newLotId));
        $this->assertSame([(int) $root->id], $this->lineage()->rootAncestorIdsOf($mergeResult->newLotId));
    }
}
