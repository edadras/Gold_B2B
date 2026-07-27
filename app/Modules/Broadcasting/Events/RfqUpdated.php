<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Events;

use App\Modules\Broadcasting\Domain\ChannelName;
use App\Modules\Broadcasting\Domain\Payload;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * RFQ and OTC activity on `private-org.{orgId}.rfq` — §3.2 («RFQ و پیشنهادها»).
 *
 * §3.3 does not spell out a payload for this channel, so the shape here is the
 * minimum a client needs to refresh the right row: which negotiation, what kind,
 * what state, and — when the RFQ is not anonymous — who. It deliberately does
 * NOT carry the price ladder; a quote list is a REST read, and pushing it here
 * would put another member's live pricing into a payload that has to be right
 * about anonymity every single time.
 *
 * ANONYMITY. RfqCreated carries a `visibility` of ANONYMOUS for a reason
 * (§4.7 rule 5): the recipients must be able to quote without knowing who
 * asked. The listener passes `counterpartyOrganizationId` only when visibility
 * permits it, and this event has no branch that could reintroduce it.
 *
 * Not throttled — these are human-paced events, a handful an hour.
 */
final class RfqUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public const KIND_RFQ = 'RFQ';

    public const KIND_OTC = 'OTC';

    public function __construct(
        public readonly int $organizationId,
        public readonly string $kind,
        public readonly int $subjectId,
        public readonly string $status,
        public readonly ?string $code = null,
        public readonly ?int $counterpartyOrganizationId = null,
        public readonly ?int $quantityMg = null,
        public readonly ?int $priceRial = null,
        public readonly ?string $side = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $timestamp = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelName::organizationRfq($this->organizationId))];
    }

    public function broadcastAs(): string
    {
        return 'rfq.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            'kind' => $this->kind,
            'id' => $this->subjectId,
            'code' => $this->code,
            'status' => $this->status,
            'counterparty_organization_id' => $this->counterpartyOrganizationId,
            'quantity_mg' => $this->quantityMg,
            'price_rial' => $this->priceRial,
            'side' => $this->side,
            'expires_at' => $this->expiresAt,
            'timestamp' => $this->timestamp,
        ]);
    }
}
