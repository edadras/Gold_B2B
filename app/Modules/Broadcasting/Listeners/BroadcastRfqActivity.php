<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MemberBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;
use App\Modules\Broadcasting\Events\RfqUpdated;

/**
 * RFQ and OTC activity → `rfq.updated` on `private-org.{orgId}.rfq` (§3.2).
 *
 * ANONYMITY IS THE WHOLE JOB HERE. RfqCreated carries `visibility`, and §4.7
 * rule 5 requires the requester's identity to be withheld from recipients when
 * it is ANONYMOUS. So:
 *
 *   · the requester always learns who they asked — it is their own RFQ;
 *   · a recipient learns the requester's id ONLY when visibility is not
 *     ANONYMOUS;
 *   · when the quote is accepted the veil drops for both, because a trade is
 *     about to exist and the counterparty is part of it.
 *
 * OTC has no anonymity: an offer is sent to a named counterparty by
 * definition (§4.6), so both sides always see each other.
 */
final class BroadcastRfqActivity
{
    private const ANONYMOUS = 'ANONYMOUS';

    public function __construct(private readonly MemberBroadcaster $members) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);

        match ($this->basename($event)) {
            'RfqCreated' => $this->rfqCreated($shape),
            'RfqQuoted' => $this->rfqQuoted($shape),
            'RfqAccepted' => $this->rfqAccepted($shape),
            'RfqExpired' => $this->rfqExpired($shape),
            'OtcOfferCreated' => $this->otcCreated($shape),
            'OtcOfferCountered' => $this->otcCountered($shape),
            'OtcOfferAccepted' => $this->otcAccepted($shape),
            'OtcOfferRejected' => $this->otcRejected($shape),
            default => null,
        };
    }

    private function rfqCreated(EventShape $shape): void
    {
        $rfqId = $shape->int('rfqId');
        $requesterId = $shape->int('organizationId');

        if ($rfqId === null || $requesterId === null) {
            return;
        }

        $anonymous = $shape->string('visibility') === self::ANONYMOUS;
        $quantity = $shape->int('quantityMg');
        $side = $shape->string('side');
        $expiresAt = $shape->string('expiresAt');
        $code = $shape->string('rfqCode');

        // The requester's own copy.
        $this->members->rfqUpdated(
            organizationId: $requesterId,
            kind: RfqUpdated::KIND_RFQ,
            subjectId: $rfqId,
            status: 'OPEN',
            code: $code,
            quantityMg: $quantity,
            side: $side,
            expiresAt: $expiresAt,
        );

        foreach ($shape->list('recipientOrgIds') ?? [] as $recipientId) {
            if (! is_int($recipientId) || $recipientId === $requesterId) {
                continue;
            }

            $this->members->rfqUpdated(
                organizationId: $recipientId,
                kind: RfqUpdated::KIND_RFQ,
                subjectId: $rfqId,
                status: 'RECEIVED',
                code: $code,
                counterpartyOrganizationId: $anonymous ? null : $requesterId,
                quantityMg: $quantity,
                side: $side,
                expiresAt: $expiresAt,
            );
        }
    }

    private function rfqQuoted(EventShape $shape): void
    {
        $rfqId = $shape->int('rfqId');
        $quoterId = $shape->int('quoterOrganizationId');
        $requesterId = $shape->int('requesterOrganizationId');

        if ($rfqId === null || $quoterId === null || $requesterId === null) {
            return;
        }

        $quantity = $shape->int('quantityMg');
        $price = $shape->int('pricePerGramRial');
        $code = $shape->string('quoteCode');

        // The requester sees a new quote arrive. The quoter's identity is the
        // requester's to know — they chose who to ask.
        $this->members->rfqUpdated(
            organizationId: $requesterId,
            kind: RfqUpdated::KIND_RFQ,
            subjectId: $rfqId,
            status: 'QUOTED',
            code: $code,
            counterpartyOrganizationId: $quoterId,
            quantityMg: $quantity,
            priceRial: $price,
            expiresAt: $shape->string('validUntil'),
        );

        // The quoter sees their own quote acknowledged, still without learning
        // who asked — this event does not say, and neither does this frame.
        $this->members->rfqUpdated(
            organizationId: $quoterId,
            kind: RfqUpdated::KIND_RFQ,
            subjectId: $rfqId,
            status: 'QUOTE_SENT',
            code: $code,
            quantityMg: $quantity,
            priceRial: $price,
            expiresAt: $shape->string('validUntil'),
        );
    }

    private function rfqAccepted(EventShape $shape): void
    {
        $rfqId = $shape->int('rfqId');
        $requesterId = $shape->int('requesterOrganizationId');
        $quoterId = $shape->int('quoterOrganizationId');

        if ($rfqId === null || $requesterId === null || $quoterId === null) {
            return;
        }

        $status = $shape->bool('fullyAccepted') === true ? 'ACCEPTED' : 'PARTIALLY_ACCEPTED';
        $quantity = $shape->int('acceptedMg');
        $price = $shape->int('pricePerGramRial');

        // A trade exists now, so the veil drops on both sides.
        $this->members->rfqUpdated(
            organizationId: $requesterId,
            kind: RfqUpdated::KIND_RFQ,
            subjectId: $rfqId,
            status: $status,
            counterpartyOrganizationId: $quoterId,
            quantityMg: $quantity,
            priceRial: $price,
        );

        $this->members->rfqUpdated(
            organizationId: $quoterId,
            kind: RfqUpdated::KIND_RFQ,
            subjectId: $rfqId,
            status: $status,
            counterpartyOrganizationId: $requesterId,
            quantityMg: $quantity,
            priceRial: $price,
        );
    }

    private function rfqExpired(EventShape $shape): void
    {
        $rfqId = $shape->int('rfqId');
        $organizationId = $shape->int('organizationId');

        if ($rfqId === null || $organizationId === null) {
            return;
        }

        $this->members->rfqUpdated(
            organizationId: $organizationId,
            kind: RfqUpdated::KIND_RFQ,
            subjectId: $rfqId,
            status: 'EXPIRED',
            quantityMg: $shape->int('quantityMg'),
        );
    }

    private function otcCreated(EventShape $shape): void
    {
        $offerId = $shape->int('offerId');
        $initiatorId = $shape->int('initiatorOrganizationId');
        $counterpartyId = $shape->int('counterpartyOrganizationId');

        if ($offerId === null || $initiatorId === null || $counterpartyId === null) {
            return;
        }

        $this->otcPair(
            $offerId,
            $initiatorId,
            $counterpartyId,
            'SENT',
            'RECEIVED',
            $shape->string('offerCode'),
            $shape->int('quantityMg'),
            $shape->int('priceRial'),
            $shape->string('side'),
            $shape->string('expiresAt'),
        );
    }

    private function otcCountered(EventShape $shape): void
    {
        $offerId = $shape->int('offerId');
        $proposerId = $shape->int('proposerOrganizationId');
        $responderId = $shape->int('responderOrganizationId');

        if ($offerId === null || $proposerId === null || $responderId === null) {
            return;
        }

        $this->otcPair(
            $offerId,
            $proposerId,
            $responderId,
            'COUNTERED',
            'COUNTERED',
            null,
            $shape->int('quantityMg'),
            $shape->int('priceRial'),
        );
    }

    private function otcAccepted(EventShape $shape): void
    {
        $offerId = $shape->int('offerId');
        $buyerId = $shape->int('buyerOrganizationId');
        $sellerId = $shape->int('sellerOrganizationId');

        if ($offerId === null || $buyerId === null || $sellerId === null) {
            return;
        }

        $this->otcPair(
            $offerId,
            $buyerId,
            $sellerId,
            'ACCEPTED',
            'ACCEPTED',
            null,
            $shape->int('quantityMg'),
            $shape->int('priceRial'),
        );
    }

    private function otcRejected(EventShape $shape): void
    {
        $offerId = $shape->int('offerId');
        $actorId = $shape->int('actorOrganizationId');
        $counterpartyId = $shape->int('counterpartyOrganizationId');

        if ($offerId === null || $actorId === null || $counterpartyId === null) {
            return;
        }

        $status = $shape->stringOr('status', 'REJECTED');

        $this->otcPair($offerId, $actorId, $counterpartyId, $status, $status);
    }

    /** Both sides of an OTC negotiation, each naming the other. */
    private function otcPair(
        int $offerId,
        int $firstOrganizationId,
        int $secondOrganizationId,
        string $firstStatus,
        string $secondStatus,
        ?string $code = null,
        ?int $quantityMg = null,
        ?int $priceRial = null,
        ?string $side = null,
        ?string $expiresAt = null,
    ): void {
        $this->members->rfqUpdated(
            organizationId: $firstOrganizationId,
            kind: RfqUpdated::KIND_OTC,
            subjectId: $offerId,
            status: $firstStatus,
            code: $code,
            counterpartyOrganizationId: $secondOrganizationId,
            quantityMg: $quantityMg,
            priceRial: $priceRial,
            side: $side,
            expiresAt: $expiresAt,
        );

        $this->members->rfqUpdated(
            organizationId: $secondOrganizationId,
            kind: RfqUpdated::KIND_OTC,
            subjectId: $offerId,
            status: $secondStatus,
            code: $code,
            counterpartyOrganizationId: $firstOrganizationId,
            quantityMg: $quantityMg,
            priceRial: $priceRial,
            side: $side,
            expiresAt: $expiresAt,
        );
    }

    private function basename(object $event): string
    {
        $class = $event::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
