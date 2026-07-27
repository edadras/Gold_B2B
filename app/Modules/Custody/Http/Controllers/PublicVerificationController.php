<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Controllers;

use App\Modules\Custody\Application\QrTokenService;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `GET /verify/{qr_token}` — PUBLIC, NO AUTHENTICATION.
 *
 * Anyone holding the physical piece can scan the token engraved on it and see
 * what the platform knows about that metal. That is the point: a buyer in a
 * bazaar verifies a bar without an account.
 *
 * SECURITY — the whole design of this endpoint is about what it must NOT say.
 * The response body is PublicLotView::toArray() verbatim, and nothing else is
 * merged into it:
 *
 *   · no owner. GoldLotSnapshot and GoldLotModel both carry
 *     `owner_organization_id`, so neither may be handed to this response —
 *     QrTokenService::lookup() returns PublicLotView precisely because that
 *     class has no such property to leak. Serialising a snapshot "and then
 *     removing the owner" would be one refactor away from a disclosure;
 *   · no counterparty, no price, no settlement, no trade. A token is engraved
 *     once and never rotates, so anyone who ever photographed the bar keeps
 *     the ability to read this page — including a former owner, a courier, or
 *     a competitor who saw it on a counter. A commercial history behind a
 *     permanent public URL is a business-intelligence feed;
 *   · no custodian identity. `custody_state` says IN_VAULT, never which vault
 *     or which box: "this metal is in a vault" is reassurance, "this metal is
 *     in vault V01 box B14" is a shopping list.
 *
 * No `_display` companions either — "verbatim" means verbatim, and every extra
 * field is another chance to leak one.
 *
 * An unknown or malformed token is 404, the same answer as a token that never
 * existed, so the endpoint cannot be used to enumerate live tokens. The
 * `throttle:api` limiter on the route caps the scan rate; because the caller is
 * anonymous the limiter keys on IP.
 */
final class PublicVerificationController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly QrTokenService $qrTokens,
    ) {
        parent::__construct($authorization);
    }

    /**
     * No permit() call here, and none is missing: there is no caller to
     * authorise and no tenant to scope to. The control on this endpoint is the
     * shape of PublicLotView, not a permission.
     */
    public function show(string $qrToken): JsonResponse
    {
        $view = $this->qrTokens->lookup($qrToken);

        if ($view === null) {
            throw $this->notFound();
        }

        return ApiResponse::item($view->toArray());
    }
}
