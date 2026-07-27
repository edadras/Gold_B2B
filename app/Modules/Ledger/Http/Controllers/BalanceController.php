<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Ledger\Application\BalanceQueryService;
use App\Modules\Ledger\Http\Resources\BalanceSummaryResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /balances` — docs/05-api/02-endpoints.md §2.3. */
final class BalanceController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly BalanceQueryService $balances,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Two-legged, as everywhere: permit() asks Identity whether this user's
        // roles grant balance.view AND whether $organizationId is the caller's
        // own. The query below is additionally scoped to the same id, so a
        // future refactor that loosened one leg would still not cross tenants —
        // but neither check is redundant. A scope alone is bypassed by any raw
        // query; a role check alone would let a VIEWER read a stranger's book.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::item(new BalanceSummaryResource($this->balances->summary($organizationId)));
    }

    public function gold(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::item($this->balances->gold($organizationId));
    }

    public function rial(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::item($this->balances->rial($organizationId));
    }
}
