<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Controllers;

use App\Modules\Custody\Application\VaultQueryService;
use App\Modules\Custody\Http\Resources\VaultResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /vaults` — docs/05-api/02-endpoints.md §2.10. */
final class VaultController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly VaultQueryService $queries,
    ) {
        parent::__construct($authorization);
    }

    /**
     * Vaults currently accepting deposits.
     *
     * THIS IS PLATFORM REFERENCE DATA, NOT TENANT DATA. Vaults belong to the
     * operator; every organisation sees the same list, and there is no
     * organisation id on a vault to compare anything against.
     *
     * AUTHORISATION — that makes this the one endpoint in the module where
     * only the role leg has content. permit() is still given the caller's own
     * organisation id, because the gateway also gates on organisation status
     * (a SUSPENDED member gets 423, not a vault list), but the tenancy
     * comparison it performs is the caller's id against itself — a formality
     * here, and deliberately so.
     *
     * The corollary is a constraint on the payload rather than on the check:
     * because there is no tenancy leg to lean on, nothing member-specific may
     * ever be added to VaultResource. Occupancy, box assignments or "your
     * holdings here" would each turn a shared list into a cross-tenant leak
     * that no authorisation code on this route would catch.
     */
    public function index(Request $request): JsonResponse
    {
        $this->permit($request, Permission::LOT_VIEW->value, $this->organizationId($request));

        return ApiResponse::collection(
            VaultResource::collection($this->queries->vaultsAcceptingDeposits()),
        );
    }
}
