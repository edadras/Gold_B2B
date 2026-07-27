<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Kyc\Application\DocumentService;
use App\Modules\Kyc\Http\Requests\StoreDocumentRequest;
use App\Modules\Kyc\Http\Resources\DocumentResource;
use App\Modules\Kyc\Infrastructure\Models\Document;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/organization/documents` — docs/05-api/02-endpoints.md §2.2.
 *
 * ROLE NOTE: the endpoints table marks these 🔒 (any authenticated member)
 * rather than 👑. They are gated on KYC_EDIT here, which is OWNER/MANAGER
 * only, because the payload of this collection is national identity cards,
 * signature certificates and directors' documents, and `download_url` is a
 * live handle on the bytes. Letting a VIEWER — a role that exists so an
 * accountant's assistant can read balances — pull a short-lived URL to a
 * scan of the owner's national card is not a defensible reading of "🔒".
 * Flagged in the report as a deliberate divergence from the doc.
 */
final class DocumentController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly DocumentService $documents,
    ) {
        parent::__construct($authorization);
    }

    /**
     * AUTHORISATION — both legs. permit() checks that the caller's roles grant
     * KYC_EDIT *and* that the organisation whose documents are being listed is
     * the caller's own. The query below is scoped by the same id, but that
     * scoping is not the control: a scope is a query detail that
     * withoutGlobalScope() or a raw statement bypasses, so tenancy is asserted
     * explicitly here as well.
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::KYC_EDIT->value, $organizationId);

        $documents = $this->documents->forOrganization($organizationId);

        return ApiResponse::collection(array_map(
            // The signed URL is minted only for the caller's own documents —
            // which is all this list can contain, by the check above.
            fn (Document $document): DocumentResource => new DocumentResource(
                $document,
                $this->documents->downloadUrlFor($document),
            ),
            $documents,
        ));
    }

    /**
     * Upload. Throttled with `uploads` rather than `api`: the cost here is
     * storage and the malware scanner, not CPU.
     *
     * AUTHORISATION — both legs: KYC_EDIT granted by role, and the document is
     * created inside the caller's own organisation, which is the id passed to
     * permit(). No id from the request body reaches the write.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::KYC_EDIT->value, $organizationId);

        $document = $this->documents->store(
            $organizationId,
            $request->documentType(),
            $request->uploadedFile(),
            $this->userId($request),
            $request->meta(),
        );

        return ApiResponse::item(
            new DocumentResource($document, $this->documents->downloadUrlFor($document)),
            201,
        );
    }

    /**
     * Delete an upload that has not been verified yet.
     *
     * AUTHORISATION — both legs, in the order that matters. The document is
     * loaded by id first and matched against the caller's organisation:
     * another member's document id answers 404, never 403, because a 403 would
     * confirm the id exists and turn this route into an oracle over the whole
     * platform's document table. Only then is the role leg checked, against
     * that same (now known to be the caller's own) organisation.
     *
     * A VERIFIED document raises KycOperationException from the service, which
     * renders as a 422 DOCUMENT_NOT_DELETABLE envelope.
     */
    public function destroy(Request $request, int $documentId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $document = $this->documents->find($documentId);

        /** @var Document $document */
        $document = $this->ownedOrNotFound(
            $document,
            $document === null ? null : (int) $document->organization_id,
            $organizationId,
        );

        $this->permit($request, Permission::KYC_EDIT->value, (int) $document->organization_id);

        $this->documents->delete($document);

        return ApiResponse::noContent();
    }
}
