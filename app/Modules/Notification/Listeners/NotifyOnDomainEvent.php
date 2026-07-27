<?php

declare(strict_types=1);

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Contracts\Notifier;
use App\Modules\Notification\Domain\NotificationCode;
use Illuminate\Support\Facades\Log;

/**
 * Turns domain events from other modules into catalogue notifications.
 *
 * The events belong to Trading, Settlement, Custody, Kyc and Dispute — none of
 * which this module may depend on — so they are subscribed to by class *name*
 * and read by shape. The map below is the whole integration: a module that is
 * not deployed simply produces no events, and a payload that does not look like
 * what we expect is logged and dropped rather than throwing inside a listener.
 *
 * The notification code is derived from the event's own class name, so adding a
 * settlement event upstream needs one line here and nothing else.
 */
final class NotifyOnDomainEvent
{
    /**
     * Event class name => catalogue code.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'App\Modules\Trading\Events\OrderFilled' => 'ORDER_FILLED',
        'App\Modules\Trading\Events\OrderPartiallyFilled' => 'ORDER_PARTIAL',
        'App\Modules\Trading\Events\OrderRejected' => 'ORDER_REJECTED',
        'App\Modules\Trading\Events\OtcOfferReceived' => 'OTC_OFFER_RECEIVED',
        'App\Modules\Trading\Events\RfqQuoteAccepted' => 'RFQ_ACCEPTED',
        'App\Modules\Settlement\Events\SettlementOpened' => 'SETTLEMENT_OPENED',
        'App\Modules\Settlement\Events\PaymentRequired' => 'PAYMENT_REQUIRED',
        'App\Modules\Settlement\Events\PaymentDeclared' => 'PAYMENT_DECLARED',
        'App\Modules\Settlement\Events\SettlementCompleted' => 'SETTLEMENT_COMPLETED',
        'App\Modules\Settlement\Events\SettlementOverdue' => 'SETTLEMENT_OVERDUE',
        'App\Modules\Settlement\Events\SettlementDefaulted' => 'SETTLEMENT_DEFAULTED',
        'App\Modules\Custody\Events\AssayVarianceDetected' => 'ASSAY_VARIANCE',
        'App\Modules\Kyc\Events\KycApproved' => 'KYC_APPROVED',
        'App\Modules\Dispute\Events\DisputeOpened' => 'DISPUTE_OPENED_AGAINST',
        'App\Modules\Dispute\Events\DisputeResolved' => 'DISPUTE_RESOLVED',
    ];

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(object $event): void
    {
        $code = NotificationCode::tryFrom(self::MAP[$event::class] ?? '');

        if ($code === null) {
            return;
        }

        $organizationId = $this->organizationId($event);

        if ($organizationId === null) {
            Log::debug('Notification ignored an event without an organisation', [
                'event' => $event::class,
            ]);

            return;
        }

        $this->notifier->dispatch(new NotificationSpec(
            code: $code,
            organizationId: $organizationId,
            params: $this->params($event),
            subjectType: $this->subjectType($event),
            subjectId: $this->subjectId($event),
        ));
    }

    private function organizationId(object $event): ?int
    {
        foreach (['organizationId', 'orgId', 'buyerOrgId', 'ownerOrgId'] as $property) {
            if (property_exists($event, $property)) {
                $value = (int) $event->{$property};

                return $value > 0 ? $value : null;
            }
        }

        return null;
    }

    /** @return array<string, string|int> */
    private function params(object $event): array
    {
        $params = [];

        foreach (['fineWeightMg' => 'weight', 'amountRial' => 'amount', 'reference' => 'reference'] as $property => $key) {
            if (property_exists($event, $property)) {
                $value = $event->{$property};

                if (is_scalar($value)) {
                    $params[$key] = is_int($value) ? $value : (string) $value;
                }
            }
        }

        return $params;
    }

    private function subjectType(object $event): ?string
    {
        foreach (['settlementId' => 'settlement', 'orderId' => 'order', 'disputeId' => 'dispute', 'lotId' => 'lot'] as $property => $type) {
            if (property_exists($event, $property)) {
                return $type;
            }
        }

        return null;
    }

    private function subjectId(object $event): ?int
    {
        foreach (['settlementId', 'orderId', 'disputeId', 'lotId'] as $property) {
            if (property_exists($event, $property)) {
                return (int) $event->{$property};
            }
        }

        return null;
    }
}
