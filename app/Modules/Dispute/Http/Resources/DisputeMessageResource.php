<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Resources;

use App\Modules\Dispute\Domain\MessageType;
use App\Modules\Dispute\Infrastructure\Models\DisputeMessageModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One line of the negotiation room — `/messages` and `/propose-settlement`.
 *
 * The proposed amounts are UNSIGNED and stated from the SENDER's perspective:
 * they are what the sender offered to hand over. The case's own frame (positive
 * = the claimant receives) is applied by NegotiationService when a proposal is
 * accepted, so a client rendering this list must not add a sign of its own.
 *
 * @mixin DisputeMessageModel
 */
final class DisputeMessageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DisputeMessageModel $message */
        $message = $this->resource;

        $type = MessageType::tryFrom((string) $message->message_type);

        return [
            'id' => (int) $message->id,
            'dispute_id' => (int) $message->dispute_id,
            'message_type' => (string) $message->message_type,
            'is_proposal' => $type?->isProposal() ?? false,
            'body' => (string) $message->body,
            'sender_org_id' => (int) $message->sender_org_id,
            'proposed_gold_mg' => (int) $message->proposed_gold_mg,
            'proposed_rial' => (int) $message->proposed_rial,
            'responds_to_message_id' => $message->responds_to_message_id === null
                ? null
                : (int) $message->responds_to_message_id,
            'resolution' => $message->resolution,
            'created_at' => Display::iso($message->created_at),
        ] + $this->display($request, [
            'message_type_display' => $type?->label(),
            'proposed_gold_display' => Display::grams((int) $message->proposed_gold_mg),
            'proposed_rial_display' => Display::rial((int) $message->proposed_rial),
            'created_at_jalali' => Display::jalali($message->created_at),
        ]);
    }
}
