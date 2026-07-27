<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Webhook\Application\WebhookDispatcher;
use App\Modules\Webhook\Application\WebhookRegistrar;
use App\Modules\Webhook\Contracts\IssuedSecret;
use App\Modules\Webhook\Domain\Exceptions\WebhookDisabledException;
use App\Modules\Webhook\Http\Requests\RegisterWebhookRequest;
use App\Modules\Webhook\Http\Requests\UpdateWebhookRequest;
use App\Modules\Webhook\Http\Resources\WebhookDeliveryResource;
use App\Modules\Webhook\Http\Resources\WebhookResource;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The first seven rows of the §3.13 management table.
 *
 * ─────────────────────────────── TENANCY ───────────────────────────────
 *
 * A webhook is a *credential* — whoever controls the URL receives a member's
 * trade flow, and whoever can rotate the secret can silence it. So every action
 * does both legs (ApiController::permit) and then loads the row through
 * WebhookRegistrar::findForOrganization, which filters on the caller's
 * organisation in the WHERE clause.
 *
 * A webhook belonging to another organisation is answered 404, never 403.
 * A 403 would confirm the id exists and turn `/webhooks/{id}` into an oracle
 * that enumerates which of the platform's members have integrations.
 *
 * Route-model binding is not used for the same reason: it would load the row
 * before any tenant check and make the "did it exist" and "was it yours"
 * answers distinguishable by timing.
 *
 * The permission is `user.manage` (OWNER and MANAGER). Managing an integration
 * credential for the whole organisation is an administrative act, not a trading
 * one — a TRADER can place orders but has no business redirecting the
 * organisation's event stream to a server of their choosing.
 */
final class WebhookController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly WebhookRegistrar $registrar,
        private readonly WebhookDispatcher $dispatcher,
    ) {
        parent::__construct($authorization);
    }

    /** `GET /webhooks` — «فهرست webhookهای من». */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->authorizeWebhooks($request);

        return ApiResponse::collection(
            WebhookResource::collection($this->registrar->listForOrganization($organizationId)),
        );
    }

    /**
     * `POST /webhooks` — §3.7.
     *
     * The ONLY response in the module that carries a secret, alongside the
     * §3.7 warning in `meta`. 201, because a resource was created.
     */
    public function store(RegisterWebhookRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeWebhooks($request);

        $issued = $this->registrar->register(
            organizationId: $organizationId,
            url: (string) $request->input('url'),
            events: $request->events(),
            description: $request->input('description') === null ? null : (string) $request->input('description'),
        );

        return ApiResponse::item(
            $this->withSecret($request, $issued),
            201,
            ['warning' => 'این تنها بار نمایش secret است. آن را ذخیره کنید.'],
        );
    }

    /** `PUT /webhooks/{id}` — «ویرایش رویدادها». */
    public function update(UpdateWebhookRequest $request, int $webhookId): JsonResponse
    {
        $webhook = $this->ownedWebhook($request, $webhookId);

        $updated = $this->registrar->update(
            webhook: $webhook,
            url: $request->has('url') ? (string) $request->input('url') : null,
            events: $request->events(),
            description: $request->has('description') && $request->input('description') !== null
                ? (string) $request->input('description')
                : null,
        );

        return ApiResponse::item(new WebhookResource($updated));
    }

    /** `DELETE /webhooks/{id}`. */
    public function destroy(Request $request, int $webhookId): JsonResponse
    {
        $webhook = $this->ownedWebhook($request, $webhookId);

        $this->registrar->delete($webhook);

        return ApiResponse::noContent();
    }

    /**
     * `POST /webhooks/{id}/rotate-secret` — «چرخش کلید».
     *
     * The second and last place a secret is emitted. The old one is dead the
     * moment this returns, so the warning matters even more than at
     * registration: a member who loses this response has to rotate again.
     */
    public function rotateSecret(Request $request, int $webhookId): JsonResponse
    {
        $webhook = $this->ownedWebhook($request, $webhookId);

        $issued = $this->registrar->rotateSecret($webhook);

        return ApiResponse::item(
            $this->withSecret($request, $issued),
            200,
            ['warning' => 'این تنها بار نمایش secret جدید است. کلید قبلی دیگر معتبر نیست.'],
        );
    }

    /**
     * `POST /webhooks/{id}/test` — «ارسال رویداد آزمایشی».
     *
     * 202: the delivery is queued, not performed inside the request. Doing it
     * inline would hold an HTTP worker for up to ten seconds against a server we
     * do not control, which is a self-inflicted denial of service with a nice UI.
     */
    public function test(Request $request, int $webhookId): JsonResponse
    {
        $webhook = $this->ownedWebhook($request, $webhookId);

        if ($webhook->isDisabled()) {
            throw new WebhookDisabledException((int) $webhook->getKey());
        }

        $delivery = $this->dispatcher->sendTest($webhook);

        return ApiResponse::item(new WebhookDeliveryResource($delivery), 202);
    }

    /** `GET /webhooks/{id}/deliveries` — «تاریخچه ارسال». */
    public function deliveries(Request $request, int $webhookId): JsonResponse
    {
        $webhook = $this->ownedWebhook($request, $webhookId);

        $limit = (int) config('goldb2b.webhook.delivery_page_size', 50);

        $deliveries = $webhook->deliveries()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return ApiResponse::collection(WebhookDeliveryResource::collection($deliveries));
    }

    /**
     * Both authorisation legs, then a tenant-filtered load. Missing and
     * foreign are the same 404.
     */
    private function ownedWebhook(Request $request, int $webhookId): Webhook
    {
        $organizationId = $this->authorizeWebhooks($request);

        $webhook = $this->registrar->findForOrganization($webhookId, $organizationId);

        if ($webhook === null) {
            throw $this->notFound();
        }

        return $webhook;
    }

    private function authorizeWebhooks(Request $request): int
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::USER_MANAGE->value, $organizationId);

        return $organizationId;
    }

    /**
     * The resource plus the one-time plaintext.
     *
     * Assembled here rather than inside WebhookResource so that the resource —
     * which every other endpoint renders — has no code path that can emit a
     * secret at all.
     *
     * @return array<string, mixed>
     */
    private function withSecret(Request $request, IssuedSecret $issued): array
    {
        return ['secret' => $issued->secret]
            + (new WebhookResource($issued->webhook))->toArray($request);
    }
}
