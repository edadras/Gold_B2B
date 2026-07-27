<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Application\UnrealizedPnlService;
use App\Modules\Accounting\Http\Resources\UnrealizedPnlResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /accounting/unrealized-pnl` — F17, mark-to-market on stock still held.
 *
 * Deliberately NOT folded into `GET /reports/pnl`, which belongs to Reporting
 * and prints the accounting result. §9.5 keeps the two apart on purpose —
 * «معامله‌گر سود اقتصادی را می‌خواهد، حسابدار سود حسابداری را» — and this
 * figure is an opinion about a price that changes every few seconds. Reporting
 * carries it as an informational block flagged `available`; Accounting serves
 * the live figure here. Neither ever writes it to the journal.
 */
final class UnrealizedPnlController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly UnrealizedPnlService $unrealized,
    ) {
        parent::__construct($authorization);
    }

    public function show(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // BOTH LEGS. permit() asks Identity's AuthorizationGateway for two
        // things at once: that the caller's roles grant `ledger.view`, and that
        // the organisation whose inventory is being valued is the caller's own.
        // A role check alone would let any member value another member's stock
        // — which, combined with a public gold price, is a very good estimate of
        // what they hold. A tenant scope alone would not do either: the cost
        // basis is read through a query builder, and a raw query or a
        // `withoutGlobalScope()` call bypasses an Eloquent global scope
        // entirely, so tenancy is asserted here against the token's
        // organisation rather than trusted to the model layer.
        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        return ApiResponse::item(
            new UnrealizedPnlResource($this->unrealized->forOrganization($organizationId)),
        );
    }
}
