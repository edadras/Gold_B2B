<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Infrastructure\Models\SettlementEventModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One line of the settlement's history (`GET /settlements/{id}/events`).
 *
 * `actor_user_id` is exposed but no name is resolved: the counterparty is
 * entitled to know that a human on the other side acted, not who that human is.
 *
 * @mixin SettlementEventModel
 */
final class SettlementEventResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SettlementEventModel $event */
        $event = $this->resource;

        return [
            'id' => (int) $event->id,
            'from_status' => $event->from_status,
            'to_status' => (string) $event->to_status,
            'actor_type' => $event->actor_type->value,
            'reason' => $event->reason,
            'transaction_group' => $event->transaction_group,
            'occurred_at' => Display::iso($event->occurred_at),
        ] + $this->display($request, [
            'occurred_at_jalali' => Display::jalali($event->occurred_at),
        ]);
    }
}
