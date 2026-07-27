<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Support;

use App\Modules\Custody\Application\Commands\NewLotSpec;
use App\Modules\Custody\Application\LotCreator;
use App\Modules\Custody\Domain\Enums\AccreditationLevel;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\LaboratoryModel;
use App\Modules\Custody\Infrastructure\Models\VaultBoxModel;
use App\Modules\Custody\Infrastructure\Models\VaultModel;
use App\Modules\Custody\Infrastructure\Models\VaultSafeModel;
use App\Modules\Custody\Infrastructure\Models\VaultShelfModel;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * Fixtures for Custody tests.
 *
 * Lots are created through LotCreator rather than raw inserts so the tests
 * exercise the same code path production does (lot code stamping, QR token,
 * F1 fine weight).
 */
trait CreatesCustodyFixtures
{
    protected int $defaultOwnerOrgId = 184;

    protected function makeVault(string $code = 'V01', string $status = 'ACTIVE'): VaultModel
    {
        return VaultModel::query()->create([
            'vault_code' => $code,
            'name' => 'Central vault '.$code,
            'address' => 'Tehran',
            'status' => $status,
        ]);
    }

    protected function makeBox(VaultModel $vault, string $safe = 'S01', string $shelf = 'F01', string $box = 'B01'): VaultBoxModel
    {
        $safeModel = VaultSafeModel::query()->create([
            'vault_id' => $vault->id,
            'safe_code' => $safe,
            'full_code' => $vault->vault_code.'-'.$safe,
        ]);

        $shelfModel = VaultShelfModel::query()->create([
            'vault_id' => $vault->id,
            'vault_safe_id' => $safeModel->id,
            'shelf_code' => $shelf,
            'full_code' => $safeModel->full_code.'-'.$shelf,
        ]);

        return VaultBoxModel::query()->create([
            'vault_id' => $vault->id,
            'vault_safe_id' => $safeModel->id,
            'vault_shelf_id' => $shelfModel->id,
            'box_code' => $box,
            'full_code' => $shelfModel->full_code.'-'.$box,
        ]);
    }

    protected function makeLaboratory(
        AccreditationLevel $level = AccreditationLevel::TIER_1,
        string $license = 'LAB-0001',
    ): LaboratoryModel {
        return LaboratoryModel::query()->create([
            'name' => 'Laboratory '.$license,
            'license_no' => $license,
            'accreditation_level' => $level->value,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeLot(
        int $grossMg = 100_000,
        int $purityX10 = 9_950,
        ?int $ownerOrgId = null,
        LotStatus $status = LotStatus::AVAILABLE,
        PuritySource $puritySource = PuritySource::ASSAYED,
        CustodianType $custodianType = CustodianType::VAULT,
        int $custodianId = 1,
        ?int $fineMg = null,
        array $overrides = [],
    ): GoldLotModel {
        $lot = app(LotCreator::class)->create(new NewLotSpec(
            ownerOrganizationId: $ownerOrgId ?? $this->defaultOwnerOrgId,
            gross: Weight::fromMilligrams($grossMg),
            purity: Purity::fromScaled($purityX10),
            puritySource: $puritySource,
            shape: LotShape::BAR,
            originType: OriginType::MEMBER_DEPOSIT,
            custodianType: $custodianType,
            custodianId: $custodianId,
            status: $status,
            fine: $fineMg === null ? null : FineWeight::fromMilligrams($fineMg),
            createdByUserId: 1,
        ));

        if ($overrides !== []) {
            $lot->forceFill($overrides)->save();
            $lot->refresh();
        }

        return $lot;
    }
}
