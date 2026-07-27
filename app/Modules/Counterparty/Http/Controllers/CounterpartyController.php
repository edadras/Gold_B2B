<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Controllers;

use App\Modules\Counterparty\Application\BalanceConfirmationService;
use App\Modules\Counterparty\Application\CreditLimitService;
use App\Modules\Counterparty\Application\RelationService;
use App\Modules\Counterparty\Application\StatementService;
use App\Modules\Counterparty\Http\Requests\ConfirmBalanceRequest;
use App\Modules\Counterparty\Http\Requests\StatementRequest;
use App\Modules\Counterparty\Http\Requests\UpdateCounterpartyLimitsRequest;
use App\Modules\Counterparty\Http\Requests\UpdateCounterpartySettingsRequest;
use App\Modules\Counterparty\Http\Resources\BalanceConfirmationResource;
use App\Modules\Counterparty\Http\Resources\CounterpartyRelationResource;
use App\Modules\Counterparty\Http\Resources\CreditHeadroomResource;
use App\Modules\Counterparty\Http\Resources\StatementResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/counterparties/*` — docs/05-api/02-endpoints.md §2.11.
 *
 * Every relation row is one-sided and owned by the member whose
 * `organization_id` it carries: A's view of B and B's view of A are two
 * different rows with two different sets of limits, flags and notes. So the
 * `{orgId}` in these paths is *the other side*, never a resource the caller
 * could own — the resource being read or written is always
 * `relation(caller, orgId)`, and the caller's own organisation id is taken from
 * the token, never from the path.
 */
final class CounterpartyController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly RelationService $relations,
        private readonly StatementService $statements,
        private readonly CreditLimitService $creditLimits,
        private readonly BalanceConfirmationService $confirmations,
    ) {
        parent::__construct($authorization);
    }

    /** `GET /counterparties` */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // BOTH legs. `permit()` asks Identity's AuthorizationGateway, which
        // checks (1) that the organisation named here is the caller's own and
        // (2) that the caller's roles grant BALANCE_VIEW. The `where
        // organization_id` inside listFor() narrows the rows, but a query
        // filter is not an authorisation check: it says which rows, not whether
        // this caller may see any of them, and a raw query or
        // withoutGlobalScope() bypasses a scope entirely. Both legs, always.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::collection(
            CounterpartyRelationResource::collection($this->relations->listFor($organizationId))
        );
    }

    /** `GET /counterparties/{orgId}` — unknown or foreign is 404, never 403. */
    public function show(Request $request, int $counterpartyOrgId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs again: role (BALANCE_VIEW) plus tenancy on the *caller's*
        // side. Tenancy on the far side is enforced by construction — the
        // lookup key is the (caller, counterparty) pair, so there is no way to
        // address a row belonging to somebody else's book.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        $snapshot = $this->relations->snapshot($organizationId, $counterpartyOrgId);

        // A counterparty we have no relation with, and a counterparty id that
        // does not exist, are the same 404. Answering 403 or 200-with-zeros for
        // one of them would turn this endpoint into an oracle for "does member
        // X trade with member Y", which §10.7 treats as confidential.
        if ($snapshot === null || $counterpartyOrgId === $organizationId) {
            throw $this->notFound();
        }

        return ApiResponse::item(new CounterpartyRelationResource($snapshot));
    }

    /** `GET /counterparties/{orgId}/statement?from=&to=` */
    public function statement(StatementRequest $request, int $counterpartyOrgId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs. A statement is the movement history of one pair, so the
        // tenancy leg here protects the whole trading history of the caller's
        // book — a role check alone would let any authenticated member ask for
        // any pair's statement by writing two ids into the path.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        if ($counterpartyOrgId === $organizationId) {
            throw $this->notFound();
        }

        $statement = $this->statements->build(
            $organizationId,
            $counterpartyOrgId,
            $request->from(),
            $request->to(),
        );

        // No relation row means no such counterparty for this member: 404, for
        // the same reason as show().
        if ($statement->relation === null) {
            throw $this->notFound();
        }

        return ApiResponse::item(new StatementResource($statement));
    }

    /** `PUT /counterparties/{orgId}/limits` — 👑 */
    public function updateLimits(UpdateCounterpartyLimitsRequest $request, int $counterpartyOrgId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs, and this one writes. Leg 2 (role): USER_LIMIT_SET is the
        // ceiling-setting permission and is held only by OWNER and MANAGER, so
        // a TRADER extending the house's credit to a counterparty is 403
        // FORBIDDEN_ROLE. Leg 1 (tenancy): the limit is written onto
        // relation(caller, orgId), and the caller half comes from the token —
        // there is no payload field that could redirect the write into another
        // member's book, and the permission check confirms the caller may act
        // for that organisation at all.
        //
        // The Permission enum has no counterparty-credit permission of its own;
        // USER_LIMIT_SET is the nearest, and its role set matches the 👑 the
        // endpoint table gives this route.
        $this->permit($request, Permission::USER_LIMIT_SET->value, $organizationId);

        if ($counterpartyOrgId === $organizationId) {
            throw $this->notFound();
        }

        $goldLimit = $request->validated('gold_limit_mg');
        $rialLimit = $request->validated('rial_limit');

        $headroom = $this->creditLimits->setLimits(
            organizationId: $organizationId,
            counterpartyOrgId: $counterpartyOrgId,
            goldLimitMg: $goldLimit === null ? null : (int) $goldLimit,
            rialLimit: $rialLimit === null ? null : (int) $rialLimit,
        );

        return ApiResponse::item(new CreditHeadroomResource($headroom));
    }

    /** `PUT /counterparties/{orgId}/settings` — trust / block / auto-accept. */
    public function updateSettings(UpdateCounterpartySettingsRequest $request, int $counterpartyOrgId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs. OTC_TRADE is the role leg: these flags decide who the
        // member will deal with over the counter, so the roles that may trade
        // OTC are exactly the roles that may set them — a VIEWER cannot block a
        // counterparty. The tenancy leg is the caller's own organisation, since
        // flags are one-sided (§10.7): setting them changes only the caller's
        // view of the counterparty and is never visible to the other side.
        $this->permit($request, Permission::OTC_TRADE->value, $organizationId);

        if ($counterpartyOrgId === $organizationId) {
            throw $this->notFound();
        }

        $note = $request->validated('internal_note');

        $snapshot = $this->relations->setFlags(
            organizationId: $organizationId,
            counterpartyOrgId: $counterpartyOrgId,
            isTrusted: $request->flag('is_trusted'),
            isBlocked: $request->flag('is_blocked'),
            autoAcceptOtc: $request->flag('auto_accept_otc'),
            internalNote: $note === null ? null : (string) $note,
        );

        return ApiResponse::item(new CounterpartyRelationResource($snapshot));
    }

    /** `POST /counterparties/{orgId}/confirm-balance` */
    public function confirmBalance(ConfirmBalanceRequest $request, int $counterpartyOrgId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs. SETTLEMENT_CONFIRM is the role leg — there is no
        // balance-confirmation permission in the enum, and this is the nearest
        // write permission over bilateral obligations (OWNER, MANAGER,
        // TREASURER), which keeps a VIEWER from sending confirmation demands to
        // other members in the house's name. The tenancy leg puts the caller's
        // own organisation on the requester side of the row; it cannot be
        // supplied by the client.
        $this->permit($request, Permission::SETTLEMENT_CONFIRM->value, $organizationId);

        if ($counterpartyOrgId === $organizationId) {
            throw $this->notFound();
        }

        $confirmation = $this->confirmations->request(
            organizationId: $organizationId,
            counterpartyOrgId: $counterpartyOrgId,
            asOf: $request->asOf(),
            requestedByUserId: $this->userId($request),
            periodStart: $request->periodStart(),
            note: $request->note(),
        );

        return ApiResponse::item(new BalanceConfirmationResource($confirmation), 201);
    }
}
