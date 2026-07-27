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
     * Event class name => [catalogue code, property naming the RECIPIENT].
     *
     * The recipient is declared per event rather than guessed from a list of
     * likely property names, because on the events that name two organisations
     * the likely-looking one is the wrong one. «پیشنهاد شما پذیرفته شد» belongs
     * to the member who *quoted*, not the one who asked; a new OTC offer
     * belongs to the counterparty, not the initiator who already knows. A
     * blanket "first property that looks like an org id" rule sends both to the
     * wrong member, and does it silently.
     *
     * A null recipient property means the event names a single organisation and
     * the usual properties are searched.
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    private const MAP = [
        'App\Modules\Trading\Events\OrderFilled' => ['ORDER_FILLED', null],
        'App\Modules\Trading\Events\OrderPartiallyFilled' => ['ORDER_PARTIAL', null],
        'App\Modules\Trading\Events\OrderRejected' => ['ORDER_REJECTED', null],
        // The offer lands with the counterparty; the initiator sent it.
        'App\Modules\Trading\Events\OtcOfferCreated' => ['OTC_OFFER_RECEIVED', 'counterpartyOrganizationId'],
        // "Your quote was accepted" — the quoter is the one being told.
        'App\Modules\Trading\Events\RfqAccepted' => ['RFQ_ACCEPTED', 'quoterOrganizationId'],
        'App\Modules\Settlement\Events\SettlementOpened' => ['SETTLEMENT_OPENED', null],
        // Only the side that owes cash is asked to pay.
        'App\Modules\Settlement\Events\PaymentRequired' => ['PAYMENT_REQUIRED', 'cashPayerOrgId'],
        'App\Modules\Settlement\Events\PaymentDeclared' => ['PAYMENT_DECLARED', null],
        'App\Modules\Settlement\Events\SettlementCompleted' => ['SETTLEMENT_COMPLETED', null],
        'App\Modules\Settlement\Events\SettlementOverdue' => ['SETTLEMENT_OVERDUE', null],
        'App\Modules\Settlement\Events\SettlementDefaulted' => ['SETTLEMENT_DEFAULTED', null],
        // Custody names this event for what happened to the assay, not for the
        // variance it revealed; the fine weight moved and the owner must know.
        'App\Modules\Custody\Events\AssayAdjusted' => ['ASSAY_VARIANCE', 'ownerOrganizationId'],
        'App\Modules\Kyc\Events\KycApproved' => ['KYC_APPROVED', null],
        'App\Modules\Dispute\Events\DisputeOpened' => ['DISPUTE_OPENED_AGAINST', null],
        'App\Modules\Dispute\Events\DisputeResolved' => ['DISPUTE_RESOLVED', null],
    ];

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(object $event): void
    {
        [$codeName, $recipientProperty] = self::MAP[$event::class] ?? [null, null];

        $code = NotificationCode::tryFrom((string) $codeName);

        if ($code === null) {
            return;
        }

        $organizationId = $this->organizationId($event, $recipientProperty);

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

    /**
     * Who is being told.
     *
     * A declared recipient wins outright and does NOT fall back to the generic
     * search when it is missing: if the event was supposed to carry
     * `quoterOrganizationId` and no longer does, guessing at `requesterOrgId`
     * would send "your quote was accepted" to the member who did the accepting.
     * Sending nothing is recoverable; sending it to the wrong member is not.
     */
    private function organizationId(object $event, ?string $recipientProperty): ?int
    {
        $properties = $recipientProperty !== null
            ? [$recipientProperty]
            : ['organizationId', 'orgId', 'buyerOrgId', 'ownerOrgId', 'ownerOrganizationId'];

        foreach ($properties as $property) {
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
