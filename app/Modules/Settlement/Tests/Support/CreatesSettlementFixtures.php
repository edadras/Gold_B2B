<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Support;

use App\Modules\Custody\Application\Commands\NewLotSpec;
use App\Modules\Custody\Application\LotCreator;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Settlement\Application\Commands\OpenSettlementCommand;
use App\Modules\Settlement\Application\OpenSettlementService;
use App\Modules\Settlement\Domain\SettlementType;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Shared\ValueObjects\Weight;
use Carbon\CarbonImmutable;

/**
 * Fixtures for the Settlement suite.
 *
 * Everything goes through the real services — the ledger reservations that
 * Trading would have made, Custody's LotCreator, Settlement's own
 * OpenSettlementService — so a test exercises the same code path production
 * does. No raw inserts into settlements.
 */
trait CreatesSettlementFixtures
{
    /**
     * Reserve both sides exactly as Trading does before handing over, then open
     * the settlement. Worked example 1's groups g1..g4 in one call.
     */
    protected function openSettlement(
        int $tradeId,
        int $sellerOrgId,
        int $buyerOrgId,
        int $fineMg,
        int $grossRial,
        int $buyerFee = 0,
        int $sellerFee = 0,
        ?CarbonImmutable $deadlineAt = null,
        SettlementType $type = SettlementType::T0,
    ): SettlementModel {
        $this->reserveForTrade($tradeId, $sellerOrgId, $buyerOrgId, $fineMg, $grossRial + $buyerFee);

        return $this->app->make(OpenSettlementService::class)->openAndAwaitPayment(
            OpenSettlementCommand::forTrade(
                tradeId: $tradeId,
                buyerOrganizationId: $buyerOrgId,
                sellerOrganizationId: $sellerOrgId,
                fineWeightMg: $fineMg,
                cashAmountRial: $grossRial,
                buyerFeeRial: $buyerFee,
                sellerFeeRial: $sellerFee,
                settlementType: $type,
                deadlineAt: $deadlineAt,
            )
        );
    }

    /** The seller's gold and the buyer's cash move AVAILABLE → RESERVED. */
    protected function reserveForTrade(
        int $tradeId,
        int $sellerOrgId,
        int $buyerOrgId,
        int $fineMg,
        int $cashRial,
    ): void {
        $ref = LedgerReference::order($tradeId);

        if ($fineMg > 0) {
            $this->app->make(GoldLedgerInterface::class)
                ->reserve($sellerOrgId, FineWeight::fromMilligrams($fineMg), $ref);
        }

        if ($cashRial > 0) {
            $this->app->make(RialLedgerInterface::class)
                ->reserve($buyerOrgId, Rial::fromRial($cashRial), $ref);
        }
    }

    /**
     * A vaulted lot, ready to be delivered without moving.
     *
     * physical_location is set explicitly because it is the field worked
     * example 1 asserts stays untouched: "V01-S03-B14 (بدون تغییر)".
     */
    protected function makeVaultedLot(
        int $ownerOrgId,
        int $grossMg,
        int $purityX10 = 9_950,
        ?int $fineMg = null,
        string $location = 'V01-S03-B14',
        int $vaultId = 1,
        LotStatus $status = LotStatus::AVAILABLE,
    ): GoldLotModel {
        return $this->app->make(LotCreator::class)->create(new NewLotSpec(
            ownerOrganizationId: $ownerOrgId,
            gross: Weight::fromMilligrams($grossMg),
            purity: Purity::fromScaled($purityX10),
            puritySource: PuritySource::ASSAYED,
            shape: LotShape::BAR,
            originType: OriginType::MEMBER_DEPOSIT,
            custodianType: CustodianType::VAULT,
            custodianId: $vaultId,
            status: $status,
            fine: $fineMg === null ? null : FineWeight::fromMilligrams($fineMg),
            physicalLocation: $location,
            createdByUserId: 1,
        ));
    }
}
