<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Application;

use App\Modules\Broadcasting\Events\BalanceUpdated;
use App\Modules\Broadcasting\Events\NotificationDelivered;
use App\Modules\Broadcasting\Events\OrderUpdated;
use App\Modules\Broadcasting\Events\PrivateTradeExecuted;
use App\Modules\Broadcasting\Events\RfqUpdated;
use App\Modules\Broadcasting\Events\SettlementStatusChanged;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Shared\Contracts\VerificationTierDirectory;

/**
 * The private member feed (§3.2 «کانال‌های خصوصی»).
 *
 * Nothing here is throttled, and none of these events implement
 * ThrottledBroadcast, so there is no configuration that could make them so.
 *
 * COUNTERPARTY RESOLUTION lives here rather than in the listener because it is
 * the one place this module reaches into Identity, and it is worth having in
 * one readable method. `display_name` comes from Identity's snapshot and the
 * tier from Shared's VerificationTierDirectory port (Reputation binds the
 * adapter; a slice without Reputation gets null, which the client renders as
 * "unrated" rather than as BRONZE).
 */
final class MemberBroadcaster
{
    public function __construct(
        private readonly BroadcastGateway $gateway,
        private readonly IdentityDirectory $identity,
        private readonly VerificationTierDirectory $tiers,
    ) {}

    /** @param array{trade_code?: string, quantity_mg?: int, price_rial?: int}|null $lastFill */
    public function orderUpdated(
        int $organizationId,
        int $orderId,
        string $status,
        ?string $orderCode = null,
        ?int $filledMg = null,
        ?int $remainingMg = null,
        ?string $side = null,
        ?array $lastFill = null,
        ?string $reason = null,
        ?string $timestamp = null,
    ): bool {
        return $this->gateway->emit(new OrderUpdated(
            organizationId: $organizationId,
            orderId: $orderId,
            status: $status,
            orderCode: $orderCode,
            filledMg: $filledMg,
            remainingMg: $remainingMg,
            side: $side,
            lastFill: $lastFill,
            reason: $reason,
            timestamp: $timestamp ?? $this->now(),
        ));
    }

    public function balanceUpdated(
        int $organizationId,
        string $assetType,
        ?int $availableMg = null,
        ?int $reservedMg = null,
        ?int $inSettlementMg = null,
        ?int $totalMg = null,
        ?string $trigger = null,
        ?string $reference = null,
    ): bool {
        return $this->gateway->emit(new BalanceUpdated(
            organizationId: $organizationId,
            assetType: $assetType,
            availableMg: $availableMg,
            reservedMg: $reservedMg,
            inSettlementMg: $inSettlementMg,
            totalMg: $totalMg,
            trigger: $trigger,
            reference: $reference,
            timestamp: $this->now(),
        ));
    }

    /**
     * The counterparty-bearing trade, for ONE side of the trade.
     *
     * `$counterpartyOrganizationId` is the OTHER side. The caller passes both
     * ids explicitly rather than a buyer/seller pair plus a flag, because
     * getting that flag backwards is how a member learns their own name is
     * their counterparty — or worse, sees the wrong fee.
     */
    public function tradeExecuted(
        int $organizationId,
        int $counterpartyOrganizationId,
        string $tradeCode,
        string $side,
        int $quantityFineMg,
        int $pricePerGramRial,
        int $grossAmountRial,
        int $feeRial,
        int $netAmountRial,
        ?string $instrumentCode = null,
        ?string $settlementCode = null,
        ?string $settlementDeadline = null,
        ?string $executedAt = null,
    ): bool {
        return $this->gateway->emit(new PrivateTradeExecuted(
            organizationId: $organizationId,
            tradeCode: $tradeCode,
            side: $side,
            counterparty: $this->counterparty($counterpartyOrganizationId),
            quantityFineMg: $quantityFineMg,
            pricePerGramRial: $pricePerGramRial,
            grossAmountRial: $grossAmountRial,
            feeRial: $feeRial,
            netAmountRial: $netAmountRial,
            instrumentCode: $instrumentCode,
            settlementCode: $settlementCode,
            settlementDeadline: $settlementDeadline,
            executedAt: $executedAt ?? $this->now(),
        ));
    }

    public function settlementStatusChanged(
        int $organizationId,
        int $settlementId,
        ?string $settlementCode,
        ?string $fromStatus,
        string $toStatus,
        bool $requiresYourAction = false,
        ?string $actionType = null,
        ?string $deadlineAt = null,
        ?string $paymentReference = null,
    ): bool {
        return $this->gateway->emit(new SettlementStatusChanged(
            organizationId: $organizationId,
            settlementId: $settlementId,
            settlementCode: $settlementCode,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            requiresYourAction: $requiresYourAction,
            actionType: $actionType,
            deadlineAt: $deadlineAt,
            paymentReference: $paymentReference,
            timestamp: $this->now(),
        ));
    }

    /** @param array<string, scalar|null> $data */
    public function notification(
        int $organizationId,
        string $id,
        string $code,
        ?int $userId = null,
        ?string $title = null,
        ?string $body = null,
        ?string $category = null,
        ?string $priority = null,
        array $data = [],
    ): bool {
        return $this->gateway->emit(new NotificationDelivered(
            organizationId: $organizationId,
            id: $id,
            code: $code,
            userId: $userId,
            title: $title,
            body: $body,
            category: $category,
            priority: $priority,
            data: $data,
            createdAt: $this->now(),
        ));
    }

    public function rfqUpdated(
        int $organizationId,
        string $kind,
        int $subjectId,
        string $status,
        ?string $code = null,
        ?int $counterpartyOrganizationId = null,
        ?int $quantityMg = null,
        ?int $priceRial = null,
        ?string $side = null,
        ?string $expiresAt = null,
    ): bool {
        return $this->gateway->emit(new RfqUpdated(
            organizationId: $organizationId,
            kind: $kind,
            subjectId: $subjectId,
            status: $status,
            code: $code,
            counterpartyOrganizationId: $counterpartyOrganizationId,
            quantityMg: $quantityMg,
            priceRial: $priceRial,
            side: $side,
            expiresAt: $expiresAt,
            timestamp: $this->now(),
        ));
    }

    /**
     * @return array{id: int, display_name: string, verification_tier: string|null}|null
     */
    private function counterparty(int $organizationId): ?array
    {
        $organization = $this->identity->findOrganization($organizationId);

        if ($organization === null) {
            return null;
        }

        return [
            'id' => $organization->id,
            'display_name' => $organization->displayName,
            'verification_tier' => $this->tiers->tierFor($organizationId),
        ];
    }

    private function now(): string
    {
        return now()->toIso8601ZuluString('millisecond');
    }
}
