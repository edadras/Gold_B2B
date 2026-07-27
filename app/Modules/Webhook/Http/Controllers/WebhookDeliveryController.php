<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Webhook\Application\WebhookDispatcher;
use App\Modules\Webhook\Domain\Exceptions\WebhookDisabledException;
use App\Modules\Webhook\Http\Resources\WebhookDeliveryResource;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /webhook-deliveries/{id}/retry` — the last row of §3.13, «تلاش مجدد
 * دستی».
 *
 * The manual retry deliberately does NOT reset `attempts`. The count is the
 * delivery's history and a member re-sending an exhausted event should still be
 * able to see that it failed seven times first. It also means a member cannot
 * use the button to loop past the ladder: each press is one more attempt, and
 * once the ladder is spent the row goes straight back to EXHAUSTED after it.
 *
 * The SAME event_id is re-sent, which is the whole point — §3.11's receiver-side
 * deduplication means a member who already processed the event will correctly
 * ignore the replay instead of double-booking a trade.
 */
final class WebhookDeliveryController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly WebhookDispatcher $dispatcher,
    ) {
        parent::__construct($authorization);
    }

    public function retry(Request $request, int $deliveryId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::USER_MANAGE->value, $organizationId);

        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::query()->find($deliveryId);

        // Tenancy is resolved through the parent webhook: the delivery table has
        // no organisation column of its own, and adding one would be a second
        // source of truth that could disagree with the first.
        $webhook = $delivery === null
            ? null
            : Webhook::query()->find($delivery->getAttribute('webhook_id'));

        if ($delivery === null || $webhook === null
            || (int) $webhook->getAttribute('organization_id') !== $organizationId) {
            throw $this->notFound();
        }

        if ($webhook->isDisabled()) {
            throw new WebhookDisabledException((int) $webhook->getKey());
        }

        return ApiResponse::item(new WebhookDeliveryResource($this->dispatcher->retry($delivery)), 202);
    }
}
