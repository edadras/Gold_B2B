<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Contracts\DTO\AllocationItem;
use App\Modules\Custody\Contracts\LotAllocatorInterface;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Exceptions\InsufficientGoldLotsException;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** Allocation strategy — docs/03-domain/02-gold-lot-assay.md §2.10. */
#[Group('custody')]
#[Group('allocation')]
final class LotAllocatorTest extends CustodyTestCase
{
    private function allocator(): LotAllocatorInterface
    {
        return app(LotAllocatorInterface::class);
    }

    /** Pin a lot's acquisition date so FIFO order is deterministic. */
    private function acquiredAt(GoldLotModel $lot, string $timestamp): GoldLotModel
    {
        GoldLotModel::query()->whereKey($lot->id)->update(['created_at' => $timestamp]);

        return $lot->fresh();
    }

    #[Test]
    public function an_exact_match_is_preferred_over_a_split(): void
    {
        // Older and larger, but using it would force a split.
        $older = $this->makeLot(grossMg: 1_000_000, purityX10: 10_000);
        $this->acquiredAt($older, '2026-01-01 08:00:00');

        // Newer, but exactly the requested weight.
        $exact = $this->makeLot(grossMg: 250_000, purityX10: 10_000);
        $this->acquiredAt($exact, '2026-03-01 08:00:00');

        $plan = $this->allocator()->allocate(
            $this->defaultOwnerOrgId,
            FineWeight::fromMilligrams(250_000),
        );

        $this->assertTrue($plan->isExactMatch());
        $this->assertFalse($plan->requiresSplit());
        $this->assertSame(0, $plan->splitCount());
        $this->assertSame([(int) $exact->id], $plan->wholeLotIds());
        $this->assertSame(250_000, $plan->allocatedFineMg);
    }

    #[Test]
    public function without_an_exact_match_it_walks_fifo_and_splits_at_most_one_lot(): void
    {
        $first = $this->makeLot(grossMg: 100_000, purityX10: 10_000);
        $this->acquiredAt($first, '2026-01-01 08:00:00');

        $second = $this->makeLot(grossMg: 100_000, purityX10: 10_000);
        $this->acquiredAt($second, '2026-02-01 08:00:00');

        $third = $this->makeLot(grossMg: 100_000, purityX10: 10_000);
        $this->acquiredAt($third, '2026-03-01 08:00:00');

        $plan = $this->allocator()->allocate(
            $this->defaultOwnerOrgId,
            FineWeight::fromMilligrams(250_000),
        );

        $this->assertSame(250_000, $plan->allocatedFineMg);
        $this->assertTrue($plan->requiresSplit());
        $this->assertSame(1, $plan->splitCount(), 'the plan must never need more than one split');

        // Oldest first.
        $this->assertSame([(int) $first->id, (int) $second->id], $plan->wholeLotIds());

        $split = $plan->splitItem();
        $this->assertInstanceOf(AllocationItem::class, $split);
        $this->assertSame((int) $third->id, $split->lotId);
        $this->assertSame(50_000, $split->useFineMg);
        $this->assertSame(50_000, $split->remainderFineMg());
    }

    #[Test]
    public function it_prefers_a_later_exact_cover_over_splitting(): void
    {
        $big = $this->makeLot(grossMg: 100_000, purityX10: 10_000);
        $this->acquiredAt($big, '2026-01-01 08:00:00');

        // Exactly covers what is left after the first lot: no split needed.
        $rest = $this->makeLot(grossMg: 50_000, purityX10: 10_000);
        $this->acquiredAt($rest, '2026-02-01 08:00:00');

        $filler = $this->makeLot(grossMg: 90_000, purityX10: 10_000);
        $this->acquiredAt($filler, '2026-01-15 08:00:00');

        $plan = $this->allocator()->allocate(
            $this->defaultOwnerOrgId,
            FineWeight::fromMilligrams(150_000),
        );

        $this->assertFalse($plan->requiresSplit());
        $this->assertSame(150_000, $plan->allocatedFineMg);
        $this->assertSame([(int) $big->id, (int) $rest->id], $plan->wholeLotIds());
    }

    #[Test]
    public function it_throws_when_the_owner_does_not_hold_enough(): void
    {
        $this->makeLot(grossMg: 100_000, purityX10: 10_000);

        try {
            $this->allocator()->allocate(
                $this->defaultOwnerOrgId,
                FineWeight::fromMilligrams(500_000),
            );

            $this->fail('an under-funded allocation must throw');
        } catch (InsufficientGoldLotsException $e) {
            $this->assertSame('INSUFFICIENT_GOLD', $e->errorCode());
            $this->assertSame(500_000, $e->requiredFineMg);
            $this->assertSame(100_000, $e->availableFineMg);
            $this->assertSame(400_000, $e->details()['shortfall_fine_mg']);
        }
    }

    #[Test]
    public function it_never_allocates_a_lot_owned_by_someone_else(): void
    {
        $mine = $this->makeLot(grossMg: 100_000, purityX10: 10_000, ownerOrgId: 184);
        $theirs = $this->makeLot(grossMg: 900_000, purityX10: 10_000, ownerOrgId: 291);

        $plan = $this->allocator()->allocate(184, FineWeight::fromMilligrams(100_000));

        $this->assertSame([(int) $mine->id], $plan->lotIds());
        $this->assertNotContains((int) $theirs->id, $plan->lotIds());

        // Their gold does not even count towards my balance.
        $this->assertSame(
            100_000,
            $this->allocator()->availableFineWeight(184)->milligrams,
        );

        $this->expectException(InsufficientGoldLotsException::class);
        $this->allocator()->allocate(184, FineWeight::fromMilligrams(200_000));
    }

    #[Test]
    public function it_never_allocates_a_lot_that_is_not_available(): void
    {
        $available = $this->makeLot(grossMg: 100_000, purityX10: 10_000);

        foreach ([LotStatus::RESERVED, LotStatus::IN_SETTLEMENT, LotStatus::ON_HOLD, LotStatus::UNDER_ASSAY, LotStatus::IN_TRANSIT, LotStatus::WITHDRAWN, LotStatus::CONSUMED] as $status) {
            $this->makeLot(grossMg: 500_000, purityX10: 10_000, status: $status);
        }

        $plan = $this->allocator()->allocate(
            $this->defaultOwnerOrgId,
            FineWeight::fromMilligrams(100_000),
        );

        $this->assertSame([(int) $available->id], $plan->lotIds());

        foreach ($plan->items as $item) {
            $lot = GoldLotModel::query()->findOrFail($item->lotId);
            $this->assertSame(LotStatus::AVAILABLE, $lot->status);
            $this->assertSame($this->defaultOwnerOrgId, (int) $lot->owner_organization_id);
        }

        // Everything else is invisible, so anything larger is under-funded.
        $this->expectException(InsufficientGoldLotsException::class);
        $this->allocator()->allocate($this->defaultOwnerOrgId, FineWeight::fromMilligrams(100_001));
    }

    #[Test]
    public function it_honours_a_minimum_purity(): void
    {
        $low = $this->makeLot(grossMg: 1_000_000, purityX10: 7_500);
        $high = $this->makeLot(grossMg: 200_000, purityX10: 9_990);

        $plan = $this->allocator()->allocate(
            $this->defaultOwnerOrgId,
            FineWeight::fromMilligrams(199_800),
            Purity::fromScaled(9_950),
        );

        $this->assertSame([(int) $high->id], $plan->lotIds());
        $this->assertSame(9_950, $plan->minPurityX10);
        $this->assertNotContains((int) $low->id, $plan->lotIds());

        $this->expectException(InsufficientGoldLotsException::class);
        $this->allocator()->allocate(
            $this->defaultOwnerOrgId,
            FineWeight::fromMilligrams(500_000),
            Purity::fromScaled(9_950),
        );
    }

    #[Test]
    public function it_can_restrict_allocation_to_vault_held_gold(): void
    {
        $inVault = $this->makeLot(grossMg: 100_000, purityX10: 10_000, custodianType: CustodianType::VAULT, custodianId: 1);
        $atHome = $this->makeLot(grossMg: 900_000, purityX10: 10_000, custodianType: CustodianType::ORGANIZATION, custodianId: 184);

        $plan = $this->allocator()->allocate(
            $this->defaultOwnerOrgId,
            FineWeight::fromMilligrams(100_000),
            null,
            CustodianType::VAULT,
        );

        $this->assertSame([(int) $inVault->id], $plan->lotIds());
        $this->assertNotContains((int) $atHome->id, $plan->lotIds());
    }

    #[Test]
    public function the_plan_reports_the_lots_it_touches_in_ascending_id_order(): void
    {
        $a = $this->makeLot(grossMg: 100_000, purityX10: 10_000);
        $this->acquiredAt($a, '2026-03-01 08:00:00');

        $b = $this->makeLot(grossMg: 100_000, purityX10: 10_000);
        $this->acquiredAt($b, '2026-01-01 08:00:00');

        $plan = $this->allocator()->allocate(
            $this->defaultOwnerOrgId,
            FineWeight::fromMilligrams(150_000),
        );

        $ids = $plan->lotIds();
        $sorted = $ids;
        sort($sorted);

        $this->assertSame($sorted, $ids, 'lotIds() must be lock-order safe');
        $this->assertSame((int) $b->id, $plan->wholeLotIds()[0], 'FIFO picks the older lot first');
    }
}
