<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\Commands\SplitLotCommand;
use App\Modules\Custody\Application\Commands\SplitPart;
use App\Modules\Custody\Application\LotStateMachine;
use App\Modules\Custody\Application\SplitService;
use App\Modules\Custody\Contracts\LotAllocatorInterface;
use App\Modules\Custody\Contracts\LotDeliveryInterface;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Exceptions\LotNotOwnedException;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Infrastructure\Adapters\LotDeliveryService;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The published write side of custody — Contracts\LotDeliveryInterface.
 *
 * The claim under test is ADR-005 and docs/03-domain/06-custody-vault.md §6.2:
 * delivering gold that is already in a vault is a change of
 * owner_organization_id and nothing else. custodian_type, custodian_id and
 * physical_location are byte-for-byte identical before and after, which is why
 * the platform can settle in seconds instead of driving bullion across Tehran.
 */
#[Group('custody')]
final class LotDeliveryServiceTest extends CustodyTestCase
{
    private const SELLER = 184;

    private const BUYER = 291;

    private const OUTSIDER = 777;

    private const VAULT_ID = 7;

    private const LOCATION = 'V01-S03-B14';

    private const REFERENCE_TYPE = 'settlement';

    private const REFERENCE_ID = 4_242;

    #[Test]
    public function the_contract_is_bound_to_the_real_implementation(): void
    {
        $this->assertInstanceOf(LotDeliveryService::class, $this->delivery());
    }

    #[Test]
    public function a_plan_of_one_whole_lot_and_one_partial_lot_splits_and_changes_owner_only(): void
    {
        $whole = $this->vaultedLot(grossMg: 100_503, fineMg: 100_000);
        $partial = $this->vaultedLot(grossMg: 201_005, fineMg: 200_000);

        $custodyBefore = $this->custodyColumns((int) $whole->id);
        $parentFineBefore = (int) $partial->fine_weight_mg;

        $plan = $this->app->make(LotAllocatorInterface::class)->allocate(
            self::SELLER,
            FineWeight::fromMilligrams(250_000),
        );

        // The fixture only means something if the allocator really did produce
        // one whole lot plus one that has to be cut.
        $this->assertSame([(int) $whole->id], $plan->wholeLotIds());
        $this->assertTrue($plan->requiresSplit());
        $this->assertSame((int) $partial->id, $plan->splitItem()?->lotId);

        $result = $this->delivery()->deliver(
            plan: $plan,
            fromOrganizationId: self::SELLER,
            toOrganizationId: self::BUYER,
            referenceType: self::REFERENCE_TYPE,
            referenceId: self::REFERENCE_ID,
            actorUserId: 7_002,
        );

        // ── the split ────────────────────────────────────────────────────────
        $this->assertTrue($result->splitPerformed);
        $this->assertCount(2, $result->createdLotIds, 'the cut piece and the remainder');
        $this->assertNotNull($result->remainderLotId);
        $this->assertContains($result->remainderLotId, $result->createdLotIds);
        $this->assertSame(LotStatus::CONSUMED, $partial->fresh()->status, 'the parent never comes back');

        // ── who owns what ────────────────────────────────────────────────────
        $deliveredChild = $result->createdLotIds[0];
        $this->assertNotSame($result->remainderLotId, $deliveredChild, 'the cut piece is not the remainder');
        $this->assertSame([(int) $whole->id, $deliveredChild], $result->deliveredLotIds);
        $this->assertSame(250_000, $result->deliveredFineMg, 'exactly the promised fine weight');

        foreach ($result->deliveredLotIds as $lotId) {
            $this->assertSame(
                self::BUYER,
                (int) $this->lot($lotId)->owner_organization_id,
                "lot {$lotId} should now belong to the buyer",
            );
        }

        $remainder = $this->lot((int) $result->remainderLotId);
        $this->assertSame(self::SELLER, (int) $remainder->owner_organization_id, 'the remainder stays home');

        // ── ADR-005: no metal moved ──────────────────────────────────────────
        $this->assertTrue($result->custodyUnchanged(), 'the result must report custody as untouched');
        $this->assertSame(CustodianType::VAULT->value, $result->custodianTypeBefore);
        $this->assertSame(self::VAULT_ID, $result->custodianIdBefore);
        $this->assertSame(self::LOCATION, $result->locationBefore);
        $this->assertSame($result->custodianTypeBefore, $result->custodianTypeAfter);
        $this->assertSame($result->custodianIdBefore, $result->custodianIdAfter);
        $this->assertSame($result->locationBefore, $result->locationAfter);

        // Not just the DTO's word for it: the whole lot is a pure ownership
        // transfer, so its three custody columns must be untouched in the row.
        $this->assertSame(
            $custodyBefore,
            $this->custodyColumns((int) $whole->id),
            'an ownership transfer must never write custodian_type, custodian_id or physical_location',
        );

        // And the children inherit the parent's custody rather than acquiring
        // one of their own.
        foreach ([$deliveredChild, (int) $result->remainderLotId] as $childId) {
            $this->assertSame(
                $custodyBefore,
                $this->custodyColumns($childId),
                "child {$childId} sits exactly where the parent sat",
            );
        }

        // ── conservation ─────────────────────────────────────────────────────
        $childFine = (int) GoldLotModel::query()
            ->whereIn('id', $result->createdLotIds)
            ->sum('fine_weight_mg');

        $operation = DB::table('custody_operations')
            ->where('operation_type', 'SPLIT')
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', self::REFERENCE_ID)
            ->first();

        $this->assertNotNull($operation, 'the split is recorded against the caller reference');
        $this->assertSame($parentFineBefore, (int) $operation->input_fine_mg);
        $this->assertSame($childFine, (int) $operation->output_fine_mg);
        $this->assertSame(
            $parentFineBefore,
            (int) $operation->output_fine_mg + (int) $operation->loss_fine_mg,
            'Σ children fine + loss must equal the parent fine',
        );
        $this->assertGreaterThanOrEqual(0, (int) $operation->loss_fine_mg, 'a split may not mint gold');
    }

    #[Test]
    public function delivering_a_lot_the_sender_does_not_own_throws_and_writes_nothing(): void
    {
        $lot = $this->vaultedLot(grossMg: 201_005, fineMg: 200_000);

        $plan = $this->app->make(LotAllocatorInterface::class)->allocate(
            self::SELLER,
            FineWeight::fromMilligrams(150_000),
        );

        // The plan needs a cut, so a naive implementation would slice the lot
        // apart before discovering it belongs to someone else.
        $this->assertTrue($plan->requiresSplit());

        $lotCount = GoldLotModel::query()->count();

        try {
            $this->delivery()->deliver(
                plan: $plan,
                fromOrganizationId: self::OUTSIDER,
                toOrganizationId: self::BUYER,
                referenceType: self::REFERENCE_TYPE,
                referenceId: self::REFERENCE_ID,
            );

            $this->fail('Delivering another organization\'s metal must be refused');
        } catch (LotNotOwnedException $e) {
            $this->assertSame((int) $lot->id, $e->lotId);
            $this->assertSame('LOT_NOT_OWNED', $e->errorCode());
        }

        $lot->refresh();
        $this->assertSame(self::SELLER, (int) $lot->owner_organization_id, 'the owner is unchanged');
        $this->assertSame(LotStatus::AVAILABLE, $lot->status, 'the lot was never cut');
        $this->assertSame(200_000, (int) $lot->fine_weight_mg);
        $this->assertSame($lotCount, GoldLotModel::query()->count(), 'no children were created');
    }

    #[Test]
    public function return_to_owner_skips_a_consumed_lot_and_moves_the_rest(): void
    {
        // The buyer received four lots; two of them have since left the picture.
        $first = $this->vaultedLot(grossMg: 100_503, fineMg: 100_000, ownerOrgId: self::BUYER);
        $split = $this->vaultedLot(grossMg: 100_503, fineMg: 100_000, ownerOrgId: self::BUYER);
        $withdrawn = $this->vaultedLot(grossMg: 100_503, fineMg: 100_000, ownerOrgId: self::BUYER);
        $last = $this->vaultedLot(grossMg: 100_503, fineMg: 100_000, ownerOrgId: self::BUYER);

        $this->app->make(SplitService::class)->split(new SplitLotCommand(
            parentLotId: (int) $split->id,
            parts: [SplitPart::byGross(Weight::fromMilligrams(40_000))],
            requestedByUserId: 1,
        ));

        $this->app->make(LotStateMachine::class)->transition(
            $withdrawn,
            LotStatus::WITHDRAWN,
            new TransitionContext(actorUserId: 1, reason: 'Collected from the vault'),
        );

        $custodyBefore = $this->custodyColumns((int) $first->id);

        $result = $this->delivery()->returnToOwner(
            lotIds: [(int) $last->id, (int) $split->id, (int) $withdrawn->id, (int) $first->id, 9_999_999],
            fromOrganizationId: self::BUYER,
            toOrganizationId: self::SELLER,
            referenceType: self::REFERENCE_TYPE,
            referenceId: self::REFERENCE_ID,
            actorUserId: 7_003,
        );

        $this->assertSame([(int) $first->id, (int) $last->id], $result->deliveredLotIds);
        $this->assertSame(200_000, $result->deliveredFineMg);

        $this->assertSame(self::SELLER, (int) $first->fresh()->owner_organization_id);
        $this->assertSame(self::SELLER, (int) $last->fresh()->owner_organization_id);

        // The consumed and withdrawn lots are skipped, not failed on, and are
        // left exactly as they were — §5.8 step 4 settles those in cash.
        $this->assertSame(LotStatus::CONSUMED, $split->fresh()->status);
        $this->assertSame(self::BUYER, (int) $split->fresh()->owner_organization_id);
        $this->assertSame(LotStatus::WITHDRAWN, $withdrawn->fresh()->status);
        $this->assertSame(self::BUYER, (int) $withdrawn->fresh()->owner_organization_id);

        $this->assertTrue($result->custodyUnchanged());
        $this->assertSame($custodyBefore, $this->custodyColumns((int) $first->id));
    }

    private function delivery(): LotDeliveryInterface
    {
        return $this->app->make(LotDeliveryInterface::class);
    }

    private function vaultedLot(int $grossMg, int $fineMg, int $ownerOrgId = self::SELLER): GoldLotModel
    {
        return $this->makeLot(
            grossMg: $grossMg,
            purityX10: 9_950,
            ownerOrgId: $ownerOrgId,
            custodianType: CustodianType::VAULT,
            custodianId: self::VAULT_ID,
            fineMg: $fineMg,
            overrides: ['physical_location' => self::LOCATION],
        );
    }

    private function lot(int $lotId): GoldLotModel
    {
        return GoldLotModel::query()->findOrFail($lotId);
    }

    /**
     * The three columns ADR-005 forbids an ownership transfer from touching.
     *
     * Read straight off the table rather than through the model so a cast or an
     * accessor cannot paper over a difference.
     *
     * @return array<string, mixed>
     */
    private function custodyColumns(int $lotId): array
    {
        $row = DB::table('gold_lots')
            ->where('id', $lotId)
            ->first(['custodian_type', 'custodian_id', 'physical_location']);

        $this->assertNotNull($row, "Lot {$lotId} does not exist");

        return (array) $row;
    }
}
