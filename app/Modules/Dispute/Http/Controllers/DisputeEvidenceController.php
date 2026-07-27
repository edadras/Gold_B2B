<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Controllers;

use App\Modules\Dispute\Application\DisputeService;
use App\Modules\Dispute\Application\EvidenceService;
use App\Modules\Dispute\Http\Requests\SubmitEvidenceRequest;
use App\Modules\Dispute\Http\Resources\DisputeEvidenceResource;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /disputes/{id}/evidences` — §2.12 «افزودن مدرک». */
final class DisputeEvidenceController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly EvidenceService $evidences,
        private readonly DisputeService $disputes,
    ) {
        parent::__construct($authorization);
    }

    public function store(SubmitEvidenceRequest $request, int $disputeId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // BOTH LEGS. permit() asks Identity's AuthorizationGateway to confirm
        // that the caller's role grants `settlement.confirm` AND that the
        // organisation acting is the caller's own. Filing evidence is a party's
        // formal act in a case that can move money, which is why it sits with
        // the settlement authority rather than with `ledger.view`. Neither leg
        // is sufficient alone: a role check by itself would let a member file
        // into a stranger's case, and a tenant scope by itself is not
        // dependable — `withoutGlobalScope()` or a raw query removes an
        // Eloquent global scope entirely, so tenancy is re-asserted here
        // against the organisation on the authenticated token.
        $this->permit($request, Permission::SETTLEMENT_CONFIRM->value, $organizationId);

        // Second, narrower tenancy question: is THIS case one of ours? A case
        // the caller is not a party to is 404, never 403.
        $dispute = $this->loadCase($disputeId, $organizationId);

        $evidence = $this->evidences->submit(
            dispute: $dispute,
            organizationId: $organizationId,
            userId: $this->userId($request),
            type: $request->evidenceType(),
            description: (string) $request->validated('description'),
            documentId: $request->documentId(),
            fileHash: $request->fileHash(),
        );

        return ApiResponse::item(new DisputeEvidenceResource($evidence), 201);
    }

    /** The evidence already on a case the caller is party to. */
    public function index(Request $request, int $disputeId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Reading a case's evidence needs only the books-viewing role; both
        // legs are still checked together — see store() for why a tenant scope
        // alone would not be enough.
        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        $dispute = $this->loadCase($disputeId, $organizationId);

        return ApiResponse::collection(
            DisputeEvidenceResource::collection($dispute->evidences()->orderBy('id')->get()),
        );
    }

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
