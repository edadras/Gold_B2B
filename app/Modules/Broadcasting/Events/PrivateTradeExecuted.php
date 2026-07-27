<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Events;

use App\Modules\Broadcasting\Domain\BroadcastEventName;
use App\Modules\Broadcasting\Domain\ChannelName;
use App\Modules\Broadcasting\Domain\Payload;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * `trade.executed`, full version, on `private-org.{orgId}` — §3.3.
 *
 * Same event NAME as the public tape print, different class and different
 * channel. The client switches on `event`, and on a private channel it is
 * entitled to the counterparty; on the public one it never sees this class.
 *
 * ONE INSTANCE PER SIDE. A trade has two parties with opposite `side` values
 * and opposite counterparties, so the listener constructs this twice — once
 * for the buyer, once for the seller — rather than broadcasting a single event
 * on two channels, which would hand each side the other's view of the fee and
 * name its own organisation as the counterparty.
 *
 * Not throttled.
 */
final class PrivateTradeExecuted implements ShouldBroadcast
{
    use SerializesModels;

    /** @param array{id?: int, display_name?: string, verification_tier?: string|null}|null $counterparty */
    public function __construct(
        public readonly int $organizationId,
        public readonly string $tradeCode,
        public readonly string $side,
        public readonly ?array $counterparty,
        public readonly int $quantityFineMg,
        public readonly int $pricePerGramRial,
        public readonly int $grossAmountRial,
        public readonly int $feeRial,
        public readonly int $netAmountRial,
        public readonly ?string $instrumentCode = null,
        public readonly ?string $settlementCode = null,
        public readonly ?string $settlementDeadline = null,
        public readonly ?string $executedAt = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelName::organization($this->organizationId))];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::TRADE_EXECUTED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            'trade_code' => $this->tradeCode,
            'instrument' => $this->instrumentCode,
            'side' => $this->side,
            'counterparty' => $this->counterparty,
            'quantity_fine_mg' => $this->quantityFineMg,
            'price_per_gram_rial' => $this->pricePerGramRial,
            'gross_amount_rial' => $this->grossAmountRial,
            'fee_rial' => $this->feeRial,
            'net_amount_rial' => $this->netAmountRial,
            'settlement_code' => $this->settlementCode,
            'settlement_deadline' => $this->settlementDeadline,
            'executed_at' => $this->executedAt,
        ]);
    }
}
