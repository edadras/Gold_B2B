<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MemberBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;

/**
 * Settlement transitions → `settlement.status_changed` on
 * `private-org.{orgId}.settlement` (§3.3).
 *
 * A settlement always has two sides and they never have the same to-do. The
 * table below therefore returns a pair of "sides", each with its own
 * organisation id, `requires_your_action` and `action_type`:
 *
 *   SettlementOpened      payer → CONFIRM_PAYMENT   payee → wait
 *   PaymentDeclared       payee → CONFIRM_PAYMENT   payer → wait
 *   PaymentConfirmed      both  → wait (gold moves next)
 *
 * (§3.3's worked example names the payee's action CONFIRM_PAYMENT too — the
 * action is "confirm the payment", read from whichever end you are standing.)
 *   SettlementOverdue     payer → PAY_NOW           payee → wait
 *   SettlementDefaulted   both  → wait (a dispute is opened for them)
 *
 * Getting the pairing backwards would tell the wrong member to pay, so the
 * from/to statuses and the action live together in one arm per event and
 * nowhere else.
 *
 * `from_status` is null where the domain event does not carry one; §3.3 shows
 * it populated, and inventing a plausible previous status would be worse than
 * omitting a field the client only uses for an animation.
 *
 * `settlement_code` is likewise passed through only when the producing event
 * carries it. It is NOT rebuilt from the settlement id: the documented code
 * (STL-00088231) is derived from the TRADE id, so `sprintf('STL-%08d', $id)`
 * would be right for the events that already have it and quietly wrong for
 * every event that does not. The frame is keyed on `settlement_id` instead.
 */
final class BroadcastSettlementStatus
{
    public function __construct(private readonly MemberBroadcaster $members) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);

        if ($shape->int('settlementId') === null) {
            return;
        }

        match ($this->basename($event)) {
            'SettlementOpened' => $this->opened($shape),
            'PaymentDeclared' => $this->paymentDeclared($shape),
            'PaymentConfirmed' => $this->paymentConfirmed($shape),
            'GoldTransferred' => $this->goldTransferred($shape),
            'SettlementCompleted' => $this->completed($shape),
            'SettlementOverdue' => $this->overdue($shape),
            'SettlementDefaulted' => $this->defaulted($shape),
            'SettlementCancelled' => $this->cancelled($shape),
            'SettlementReversed' => $this->reversed($shape),
            default => null,
        };
    }

    private function opened(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $deadline = $shape->string('deadlineAt');

        $this->side($shape, 'cashPayerOrgId', $code, 'CREATED', 'PAYMENT_PENDING', true, 'CONFIRM_PAYMENT', $deadline);
        $this->side($shape, 'cashReceiverOrgId', $code, 'CREATED', 'PAYMENT_PENDING', false, null, $deadline);
    }

    private function paymentDeclared(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $reference = $shape->string('paymentReference');

        $this->side($shape, 'payeeOrganizationId', $code, 'PAYMENT_PENDING', 'PAYMENT_DECLARED', true, 'CONFIRM_PAYMENT', null, $reference);
        $this->side($shape, 'payerOrganizationId', $code, 'PAYMENT_PENDING', 'PAYMENT_DECLARED', false, null, null, $reference);
    }

    private function paymentConfirmed(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $this->side($shape, 'payerOrganizationId', $code, 'PAYMENT_DECLARED', 'PAYMENT_CONFIRMED', false, null);
        $this->side($shape, 'payeeOrganizationId', $code, 'PAYMENT_DECLARED', 'PAYMENT_CONFIRMED', false, null);
    }

    private function goldTransferred(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $this->side($shape, 'fromOrganizationId', $code, 'GOLD_TRANSFERRING', 'SETTLED', false, null);
        $this->side($shape, 'toOrganizationId', $code, 'GOLD_TRANSFERRING', 'SETTLED', false, null);
    }

    private function completed(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $this->side($shape, 'goldDelivererOrgId', $code, 'SETTLED', 'COMPLETED', false, null);
        $this->side($shape, 'goldReceiverOrgId', $code, 'SETTLED', 'COMPLETED', false, null);
    }

    private function overdue(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $deadline = $shape->string('deadlineAt');

        $this->side($shape, 'cashPayerOrgId', $code, 'PAYMENT_PENDING', 'OVERDUE', true, 'PAY_NOW', $deadline);
        $this->side($shape, 'cashReceiverOrgId', $code, 'PAYMENT_PENDING', 'OVERDUE', false, null, $deadline);
    }

    private function defaulted(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $this->side($shape, 'defaultingOrgId', $code, 'OVERDUE', 'DEFAULTED', false, null);
        $this->side($shape, 'injuredOrgId', $code, 'OVERDUE', 'DEFAULTED', false, null);
    }

    private function cancelled(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $from = $shape->string('fromStatus');

        $this->side($shape, 'cashPayerOrgId', $code, $from, 'CANCELLED', false, null);
        $this->side($shape, 'goldDelivererOrgId', $code, $from, 'CANCELLED', false, null);
    }

    private function reversed(EventShape $shape): void
    {
        $code = $shape->string('settlementCode');

        $from = $shape->string('fromStatus');

        $this->side($shape, 'goldDelivererOrgId', $code, $from, 'REVERSED', false, null);
        $this->side($shape, 'goldReceiverOrgId', $code, $from, 'REVERSED', false, null);
    }

    /**
     * One frame for one side. A missing organisation id is skipped rather than
     * defaulted — there is no safe default for "whose settlement channel".
     */
    private function side(
        EventShape $shape,
        string $organizationProperty,
        ?string $settlementCode,
        ?string $fromStatus,
        string $toStatus,
        bool $requiresAction,
        ?string $actionType,
        ?string $deadlineAt = null,
        ?string $paymentReference = null,
    ): void {
        $organizationId = $shape->int($organizationProperty);

        if ($organizationId === null) {
            return;
        }

        $this->members->settlementStatusChanged(
            organizationId: $organizationId,
            settlementId: $shape->intOr('settlementId', 0),
            settlementCode: $settlementCode,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            requiresYourAction: $requiresAction,
            actionType: $actionType,
            deadlineAt: $deadlineAt,
            paymentReference: $paymentReference,
        );
    }

    private function basename(object $event): string
    {
        $class = $event::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
