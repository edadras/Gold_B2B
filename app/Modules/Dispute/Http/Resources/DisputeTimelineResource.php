<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Resources;

use App\Modules\Dispute\Domain\ActorType;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Infrastructure\Models\DisputeTimelineModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One entry of the §13.9 case timeline.
 *
 * `actor_user_id` is NOT exposed. Which colleague on the other side pressed the
 * button is nobody's business across an organisation boundary, and the timeline
 * is read by both parties; the actor's ROLE in the case is what makes the
 * history readable.
 *
 * @mixin DisputeTimelineModel
 */
final class DisputeTimelineResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DisputeTimelineModel $entry */
        $entry = $this->resource;

        $actor = ActorType::tryFrom((string) $entry->actor_type);
        $from = $entry->from_status === null ? null : DisputeStatus::tryFrom($entry->from_status);
        $to = $entry->to_status === null ? null : DisputeStatus::tryFrom($entry->to_status);

        return [
            'id' => (int) $entry->id,
            'actor_type' => (string) $entry->actor_type,
            'actor_org_id' => $entry->actor_org_id === null ? null : (int) $entry->actor_org_id,
            'action' => (string) $entry->action,
            'message' => $entry->message,
            'from_status' => $entry->from_status,
            'to_status' => $entry->to_status,
            'occurred_at' => Display::iso($entry->occurred_at),
        ] + $this->display($request, [
            'actor_type_display' => $actor?->label(),
            'from_status_display' => $from?->label(),
            'to_status_display' => $to?->label(),
            'occurred_at_jalali' => Display::jalali($entry->occurred_at),
        ]);
    }
}
