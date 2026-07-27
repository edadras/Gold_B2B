<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Controllers;

use App\Modules\Dispute\Application\DisputeService;
use App\Modules\Dispute\Contracts\OpenDisputeCommand;
use App\Modules\Dispute\Http\Requests\AcceptClaimRequest;
use App\Modules\Dispute\Http\Requests\DisputeReasonRequest;
use App\Modules\Dispute\Http\Requests\OpenDisputeRequest;
use App\Modules\Dispute\Http\Requests\ReplyToDisputeRequest;
use App\Modules\Dispute\Http\Resources\DisputeMessageResource;
use App\Modules\Dispute\Http\Resources\DisputeResource;
use App\Modules\Dispute\Http\Resources\DisputeTimelineResource;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/disputes` — docs/05-api/02-endpoints.md §2.12.
 *
 * A dispute has TWO owning organisations, so `ownedOrNotFound()` does not
 * apply: the caller must be one of the parties, which is what
 * `partyOrNotFound()` expresses. A case the caller is not party to is 404 and
 * never 403 — a 403 would confirm the case number exists and would let anyone
 * walk the dispute table, which is a map of who is fighting whom on the
 * platform.
 *
 * ENUM COLUMNS: `DisputeModel` does not cast `status`, `dispute_type`,
 * `decision` or `priority`, so nothing here treats them as enum instances. The
 * model's `statusEnum()` / `typeEnum()` accessors are used where an enum is
 * wanted and the raw string is compared with `->value` where it is not.
 */
final class DisputeController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly DisputeService $disputes,
    ) {
        parent::__construct($authorization);
    }

    /** The caller's own cases, as claimant or as respondent. */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->permitRead($request);

        $cases = $this->disputes->listForOrganization($organizationId);

        return ApiResponse::collection(array_map(
            static fn (DisputeModel $dispute): DisputeResource => new DisputeResource($dispute, $organizationId),
            $cases,
        ));
    }

    /** Detail, with the timeline and transcript §13.9's screen renders. */
    public function show(Request $request, int $disputeId): JsonResponse
    {
        $organizationId = $this->permitRead($request);
        $dispute = $this->loadCase($disputeId, $organizationId);

        return ApiResponse::item([
            'dispute' => new DisputeResource($dispute, $organizationId),
            'timeline' => DisputeTimelineResource::collection($dispute->timeline)->toArray($request),
            'messages' => DisputeMessageResource::collection($dispute->messages)->toArray($request),
        ]);
    }

    /**
     * 🔑 File a case.
     *
     * The command object validates its own invariants (a claimant, a
     * description, a respondent or a trade, no self-disputes) and the service
     * derives the disputed amount from the trade wherever it can — so the
     * claimant cannot inflate what gets frozen on the other side by asserting a
     * figure.
     */
    public function store(OpenDisputeRequest $request): JsonResponse
    {
        $organizationId = $this->permitWrite($request);

        $dispute = $this->disputes->open(new OpenDisputeCommand(
            type: $request->disputeType(),
            claimantOrgId: $organizationId,
            openedByUserId: $this->userId($request),
            claimDescription: (string) $request->validated('claim_description'),
            tradeId: $request->nullableInt('trade_id'),
            settlementId: $request->nullableInt('settlement_id'),
            goldLotId: $request->nullableInt('gold_lot_id'),
            respondentOrgId: $request->nullableInt('respondent_org_id'),
            actualPurityX10k: $request->nullableInt('actual_purity_x10k'),
            actualFineMg: $request->nullableInt('actual_fine_mg'),
            claimRial: $request->claimRial(),
            pricePerFineGram: $request->nullableInt('price_per_fine_gram'),
        ));

        return ApiResponse::item(new DisputeResource($dispute, $organizationId), 201);
    }

    /**
     * Reject or partially accept — §13.6 مرحله ۱ options ۲ and ۳, both of which
     * lead to the negotiation room. Accepting in full is `/accept`, which is a
     * separate route precisely so it can carry the ✍️ signature gate; see
     * ReplyToDisputeRequest for the full reasoning.
     */
    public function reply(ReplyToDisputeRequest $request, int $disputeId): JsonResponse
    {
        $organizationId = $this->permitWrite($request);
        $dispute = $this->loadCase($disputeId, $organizationId);

        $updated = $this->disputes->disputeClaim($dispute, $this->userId($request), $request->message());

        return ApiResponse::item(new DisputeResource($updated, $organizationId));
    }

    /** 🔑 ✍️ Accept the claim in full — the respondent admits it. */
    public function accept(AcceptClaimRequest $request, int $disputeId): JsonResponse
    {
        $organizationId = $this->permitWrite($request);
        $dispute = $this->loadCase($disputeId, $organizationId);

        $updated = $this->disputes->acceptClaim($dispute, $this->userId($request), $request->message());

        return ApiResponse::item(new DisputeResource($updated, $organizationId));
    }

    /**
     * Ask for a mediator — §13.6 مرحله ۳.
     *
     * DisputeService::escalateToMediation() writes the user id it is given to
     * `mediator_user_id`, which for a member-initiated escalation records who
     * asked rather than who will decide. The platform's dispute queue assigns a
     * real operator when it picks the case up and overwrites the column then;
     * the timeline row (`ESCALATED_TO_MEDIATION`) is the durable record of who
     * requested it. Noted here because the column name promises more than the
     * value means at this point in the case's life.
     */
    public function escalate(DisputeReasonRequest $request, int $disputeId): JsonResponse
    {
        $organizationId = $this->permitWrite($request);
        $dispute = $this->loadCase($disputeId, $organizationId);

        $updated = $this->disputes->escalateToMediation(
            $dispute,
            $this->userId($request),
            $request->reason(),
        );

        return ApiResponse::item(new DisputeResource($updated, $organizationId));
    }

    /** The claimant drops the case; ResolutionService releases the hold. */
    public function withdraw(DisputeReasonRequest $request, int $disputeId): JsonResponse
    {
        $organizationId = $this->permitWrite($request);
        $dispute = $this->loadCase($disputeId, $organizationId);

        $updated = $this->disputes->withdraw($dispute, $this->userId($request), $request->reason());

        return ApiResponse::item(new DisputeResource($updated, $organizationId));
    }

    /**
     * BOTH LEGS OF AUTHORISATION FOR A READ.
     *
     * `permit()` asks Identity's AuthorizationGateway two questions at once:
     * does the caller's role grant `ledger.view`, and is the organisation
     * involved the caller's own? Neither alone is enough. A role check by
     * itself would let any member read any case on the platform once they
     * guessed an id. A tenant scope by itself would not save us either: the
     * dispute queries are ordinary Eloquent with an explicit
     * claimant/respondent predicate, and any global scope that might have been
     * relied on is removed by a single `withoutGlobalScope()` call or bypassed
     * entirely by a raw query — so tenancy is asserted here, at the boundary,
     * against the organisation id taken from the authenticated token.
     *
     * `permit()` settles "may this ROLE act inside this organisation". Whether
     * this particular CASE belongs to that organisation is a separate check and
     * is done by loadCase(), which answers 404 rather than 403.
     */
    private function permitRead(Request $request): int
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        return $organizationId;
    }

    /**
     * BOTH LEGS FOR A WRITE, with a stricter permission.
     *
     * `settlement.confirm` rather than `ledger.view`: opening, answering,
     * accepting or withdrawing a claim moves — or unfreezes — money and metal,
     * so it belongs with the settlement authority (OWNER, MANAGER, TREASURER),
     * not with everyone who may look at the books. A TRADER may read the case
     * they caused and may not admit liability on the organisation's behalf.
     *
     * As above, `permit()` checks the role grant AND that the organisation is
     * the caller's own — a role check alone would let a member act on a
     * stranger's case, and a tenancy scope alone is not dependable because a
     * scope is one `withoutGlobalScope()` away from being gone. The second
     * tenancy question — is this CASE one of ours? — is loadCase()'s, and it
     * answers 404.
     */
    private function permitWrite(Request $request): int
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::SETTLEMENT_CONFIRM->value, $organizationId);

        return $organizationId;
    }

    /**
     * Load a case the caller is a party to, or 404.
     *
     * Both sides are passed to partyOrNotFound(): a dispute has a claimant AND
     * a respondent, and each may act on it. A missing id and a stranger's id
     * are the same answer.
     */
    private function loadCase(int $disputeId, int $organizationId): DisputeModel
    {
        $dispute = $this->disputes->find($disputeId);

        /** @var DisputeModel */
        return $this->partyOrNotFound(
            $dispute,
            $dispute === null
                ? []
                : [(int) $dispute->claimant_org_id, (int) $dispute->respondent_org_id],
            $organizationId,
        );
    }
}
