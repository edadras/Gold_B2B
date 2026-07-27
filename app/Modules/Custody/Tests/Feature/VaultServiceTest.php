<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\Commands\DepositCommand;
use App\Modules\Custody\Application\Commands\DepositPiece;
use App\Modules\Custody\Application\Commands\WithdrawalRequestCommand;
use App\Modules\Custody\Application\VaultService;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\CustodyOperationStatus;
use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Domain\Exceptions\LotNotOwnedException;
use App\Modules\Custody\Domain\Exceptions\WaybillVerificationException;
use App\Modules\Custody\Events\LotEnteredVault;
use App\Modules\Custody\Events\LotHeld;
use App\Modules\Custody\Events\LotLeftVault;
use App\Modules\Custody\Events\LotReleased;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Custody\Infrastructure\Models\CustodyRecordModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\VaultWaybillModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** Vault IN / OUT — docs/03-domain/06-custody-vault.md §6.3, §6.4, §6.5, §6.8. */
#[Group('custody')]
#[Group('vault')]
final class VaultServiceTest extends CustodyTestCase
{
    private function service(): VaultService
    {
        return app(VaultService::class);
    }

    #[Test]
    public function a_deposit_creates_one_lot_per_piece_and_places_it_in_a_box(): void
    {
        Event::fake([LotEnteredVault::class]);

        $vault = $this->makeVault();
        $box = $this->makeBox($vault, 'S03', 'F02', 'B14');

        // The three bars from the deposit receipt in §6.3.
        $result = $this->service()->deposit(new DepositCommand(
            vaultId: (int) $vault->id,
            ownerOrganizationId: 184,
            pieces: [
                new DepositPiece(Weight::fromMilligrams(500_300), Purity::fromScaled(9_950), PuritySource::ASSAYED, LotShape::BAR, 'SN-2025-8821'),
                new DepositPiece(Weight::fromMilligrams(499_850), Purity::fromScaled(9_950), PuritySource::ASSAYED, LotShape::BAR, 'SN-2025-8822'),
                new DepositPiece(Weight::fromMilligrams(500_100), Purity::fromScaled(9_950), PuritySource::ASSAYED, LotShape::BAR, 'SN-2025-8823'),
            ],
            requestedByUserId: 10,
            executedByUserId: 11,
            defaultVaultBoxId: (int) $box->id,
        ));

        $this->assertSame(3, $result->pieceCount());
        $this->assertSame(1_500_250, $result->totalGrossMg);

        // 497,798 + 497,350 + 497,599. The receipt drawn in §6.3 shows the
        // per-piece figures rounded to the nearest milligram for display; F1
        // floors each piece, so the booked total is one milligram lower than
        // naive rounding would suggest. Flooring per piece is deliberate: the
        // system never records gold it does not hold.
        $this->assertSame(1_492_747, $result->totalFineMg);
        $this->assertSame([], $result->pendingAssayLotIds);

        foreach ($result->lotIds as $lotId) {
            $lot = GoldLotModel::query()->findOrFail($lotId);

            $this->assertSame(LotStatus::AVAILABLE, $lot->status);
            $this->assertSame(OriginType::MEMBER_DEPOSIT, $lot->origin_type);
            $this->assertSame(CustodianType::VAULT, $lot->custodian_type);
            $this->assertSame((int) $vault->id, (int) $lot->custodian_id);
            $this->assertSame('V01-S03-F02-B14', $lot->physical_location);
            $this->assertMatchesRegularExpression('/^GL-\d{8}$/', (string) $lot->lot_code);

            // One open chain-of-custody row per lot.
            $this->assertSame(
                1,
                CustodyRecordModel::query()->where('gold_lot_id', $lotId)->where('status', 'ACTIVE')->count(),
            );
        }

        $operation = CustodyOperationModel::query()->findOrFail($result->operationId);
        $this->assertSame(CustodyOperationStatus::COMPLETED, $operation->status);
        $this->assertTrue($operation->conserves());

        Event::assertDispatchedTimes(LotEnteredVault::class, 3);
    }

    #[Test]
    public function a_piece_without_a_certificate_is_booked_under_assay(): void
    {
        $vault = $this->makeVault();

        $result = $this->service()->deposit(new DepositCommand(
            vaultId: (int) $vault->id,
            ownerOrganizationId: 184,
            pieces: [
                new DepositPiece(Weight::fromMilligrams(100_000), Purity::fromScaled(9_000), PuritySource::DECLARED),
            ],
            requestedByUserId: 1,
            executedByUserId: 2,
        ));

        $this->assertSame($result->lotIds, $result->pendingAssayLotIds);

        $lot = GoldLotModel::query()->findOrFail($result->lotIds[0]);
        $this->assertSame(LotStatus::UNDER_ASSAY, $lot->status);
        $this->assertFalse($lot->purity_source->isTradableOnOrderBook());
    }

    #[Test]
    public function a_withdrawal_refuses_a_lot_that_is_not_available(): void
    {
        $vault = $this->makeVault();

        $lot = $this->makeLot(
            grossMg: 100_000,
            purityX10: 9_950,
            status: LotStatus::RESERVED,
            custodianId: (int) $vault->id,
        );

        $this->expectException(LotNotAvailableException::class);

        $this->service()->requestWithdrawal(new WithdrawalRequestCommand(
            vaultId: (int) $vault->id,
            ownerOrganizationId: $this->defaultOwnerOrgId,
            lotIds: [(int) $lot->id],
            requestedByUserId: 1,
        ));
    }

    #[Test]
    public function a_withdrawal_refuses_a_lot_owned_by_someone_else(): void
    {
        $vault = $this->makeVault();

        $lot = $this->makeLot(
            grossMg: 100_000,
            purityX10: 9_950,
            ownerOrgId: 291,
            custodianId: (int) $vault->id,
        );

        try {
            $this->service()->requestWithdrawal(new WithdrawalRequestCommand(
                vaultId: (int) $vault->id,
                ownerOrganizationId: 184,
                lotIds: [(int) $lot->id],
                requestedByUserId: 1,
            ));

            $this->fail('withdrawing another organization\'s gold must be refused');
        } catch (LotNotOwnedException $e) {
            $this->assertSame(403, $e->httpStatus());
            // The real owner is not leaked back to the caller.
            $this->assertArrayNotHasKey('owner_organization_id', $e->details());
        }

        $this->assertSame(LotStatus::AVAILABLE, $lot->fresh()->status);
    }

    #[Test]
    public function the_full_withdrawal_flow_needs_three_different_people(): void
    {
        Event::fake([LotLeftVault::class]);

        $vault = $this->makeVault();
        $box = $this->makeBox($vault);
        $lot = $this->makeLot(
            grossMg: 100_000,
            purityX10: 9_950,
            custodianId: (int) $vault->id,
            overrides: ['vault_box_id' => $box->id, 'physical_location' => $box->full_code],
        );

        $service = $this->service();

        // 1. request — lot is reserved
        $operation = $service->requestWithdrawal(new WithdrawalRequestCommand(
            vaultId: (int) $vault->id,
            ownerOrganizationId: $this->defaultOwnerOrgId,
            lotIds: [(int) $lot->id],
            requestedByUserId: 10,
            receiverName: 'Hasan Rezaei',
        ));

        $this->assertSame(CustodyOperationStatus::REQUESTED, $operation->status);
        $this->assertSame(LotStatus::RESERVED, $lot->fresh()->status);

        // 2. the requester cannot approve their own request
        try {
            $service->approveWithdrawal((int) $operation->id, 10);
            $this->fail('self-approval must be refused');
        } catch (OperationNotPermittedException $e) {
            $this->assertSame('OPERATION_NOT_PERMITTED', $e->errorCode());
        }

        $approved = $service->approveWithdrawal((int) $operation->id, 20);
        $this->assertSame(CustodyOperationStatus::APPROVED, $approved->status);
        $this->assertSame(20, (int) $approved->approved_by_user_id);

        // 3. the vault officer must be neither of the two
        foreach ([10, 20] as $conflicted) {
            try {
                $service->issueWaybill((int) $operation->id, $conflicted);
                $this->fail('segregation of duties must be enforced for user '.$conflicted);
            } catch (OperationNotPermittedException) {
                // expected
            }
        }

        $waybill = $service->issueWaybill((int) $operation->id, 30, 'Hasan Rezaei');

        $this->assertMatchesRegularExpression('/^WB-\d{8}$/', $waybill->waybillNo);
        $this->assertSame(8, strlen($waybill->oneTimeCode));

        // The plaintext code is never stored.
        $stored = VaultWaybillModel::query()->findOrFail($waybill->waybillId);
        $this->assertNotSame($waybill->oneTimeCode, $stored->one_time_code_hash);
        $this->assertSame(hash('sha256', $waybill->oneTimeCode), $stored->one_time_code_hash);

        // 4. a wrong code is rejected and counted
        try {
            $service->executeWithdrawal((int) $operation->id, '00000000', 30);
            $this->fail('a wrong one-time code must be refused');
        } catch (WaybillVerificationException $e) {
            $this->assertSame(WaybillVerificationException::REASON_BAD_CODE, $e->reason);
        }

        $this->assertSame(LotStatus::RESERVED, $lot->fresh()->status);

        $result = $service->executeWithdrawal((int) $operation->id, $waybill->oneTimeCode, 30);

        $lot->refresh();
        $this->assertSame(LotStatus::WITHDRAWN, $lot->status);
        $this->assertSame(CustodianType::ORGANIZATION, $lot->custodian_type);
        $this->assertSame($this->defaultOwnerOrgId, (int) $lot->custodian_id);
        $this->assertNull($lot->vault_box_id);
        $this->assertNull($lot->physical_location);

        $this->assertSame(CustodyOperationStatus::COMPLETED, $operation->fresh()->status);
        $this->assertSame('USED', $stored->fresh()->status);
        $this->assertSame($waybill->waybillNo, $result->waybillNo);

        Event::assertDispatchedTimes(LotLeftVault::class, 1);

        // The code cannot be replayed.
        $this->expectException(DomainException::class);
        $service->executeWithdrawal((int) $operation->id, $waybill->oneTimeCode, 30);
    }

    #[Test]
    public function a_cancelled_withdrawal_releases_the_reservation(): void
    {
        $vault = $this->makeVault();
        $lot = $this->makeLot(grossMg: 100_000, purityX10: 9_950, custodianId: (int) $vault->id);

        $service = $this->service();

        $operation = $service->requestWithdrawal(new WithdrawalRequestCommand(
            vaultId: (int) $vault->id,
            ownerOrganizationId: $this->defaultOwnerOrgId,
            lotIds: [(int) $lot->id],
            requestedByUserId: 10,
        ));

        $service->approveWithdrawal((int) $operation->id, 20);
        $issued = $service->issueWaybill((int) $operation->id, 30);

        $service->cancelWithdrawal((int) $operation->id, 20, 'Member changed their mind');

        $this->assertSame(LotStatus::AVAILABLE, $lot->fresh()->status);
        $this->assertSame(CustodyOperationStatus::CANCELLED, $operation->fresh()->status);
        $this->assertSame('CANCELLED', VaultWaybillModel::query()->findOrFail($issued->waybillId)->status);
    }

    #[Test]
    public function relocating_a_lot_moves_the_chain_of_custody_with_it(): void
    {
        $vault = $this->makeVault();
        $from = $this->makeBox($vault, 'S01', 'F01', 'B01');
        $to = $this->makeBox($vault, 'S02', 'F01', 'B07');

        $lot = $this->makeLot(
            grossMg: 100_000,
            purityX10: 9_950,
            custodianId: (int) $vault->id,
            overrides: ['vault_box_id' => $from->id, 'physical_location' => $from->full_code],
        );

        $this->service()->relocate((int) $lot->id, (int) $to->id, 12, 'Consolidating box B01');

        $lot->refresh();
        $this->assertSame((int) $to->id, (int) $lot->vault_box_id);
        $this->assertSame('V01-S02-F01-B07', $lot->physical_location);

        $active = CustodyRecordModel::query()
            ->where('gold_lot_id', $lot->id)
            ->where('status', 'ACTIVE')
            ->get();

        $this->assertCount(1, $active);
        $this->assertSame('V01-S02-F01-B07', $active[0]->physical_location);
    }

    #[Test]
    public function hold_and_release_move_the_lot_and_record_the_reason(): void
    {
        Event::fake([LotHeld::class, LotReleased::class]);

        $vault = $this->makeVault();
        $lot = $this->makeLot(grossMg: 100_000, purityX10: 9_950, custodianId: (int) $vault->id);

        $this->service()->hold((int) $lot->id, 'AML review 4412', 8, 'aml_flag', 4412);

        $lot->refresh();
        $this->assertSame(LotStatus::ON_HOLD, $lot->status);
        $this->assertSame('AML review 4412', $lot->hold_reason);
        $this->assertFalse($lot->status->isAllocatable());

        $this->service()->release((int) $lot->id, 9, 'AML review closed');

        $lot->refresh();
        $this->assertSame(LotStatus::AVAILABLE, $lot->status);
        $this->assertNull($lot->hold_reason);

        Event::assertDispatched(
            LotHeld::class,
            static fn (LotHeld $e): bool => $e->reason === 'AML review 4412'
                && $e->previousStatus === LotStatus::AVAILABLE->value
                && $e->referenceId === 4412,
        );
        Event::assertDispatchedTimes(LotReleased::class, 1);
    }
}
