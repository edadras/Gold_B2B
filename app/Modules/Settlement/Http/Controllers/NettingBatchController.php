<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Settlement\Application\NettingService;
use App\Modules\Settlement\Application\SettlementQueryService;
use App\Modules\Settlement\Http\Requests\RejectNettingRequest;
use App\Modules\Settlement\Http\Resources\NettingBatchResource;
use App\Modules\Settlement\Infrastructure\Models\NettingBatchModel;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/netting-batches` — docs/05-api/02-endpoints.md §2.9.
 *
 * Membership of a batch is the tenancy rule: a member sees a batch only if it
 * holds a position in it, which SettlementQueryService checks in SQL. A batch
 * the caller is not part of is a 404.
 */
final class NettingBatchController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly NettingService $netting,
        private readonly SettlementQueryService $query,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs: NETTING_ACCEPT is the role grant (ACCOUNTANT and above),
        // and the batch list is derived from the caller's own positions.
        $this->permit($request, Permission::NETTING_ACCEPT->value, $organizationId);

        $batches = $this->query->nettingBatchesFor($organizationId);

        return ApiResponse::collection(
            $batches->map(fn (NettingBatchModel $b): NettingBatchResource => new NettingBatchResource(
                $b,
                $this->query->positionIn((int) $b->id, $organizationId),
            ))->all(),
        );
    }

    public function show(Request $request, int $batchId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::NETTING_ACCEPT->value, $organizationId);

        $batch = $this->batchOrNotFound($batchId, $organizationId);

        return ApiResponse::item(new NettingBatchResource(
            $batch,
            $this->query->positionIn($batchId, $organizationId),
        ));
    }

    /** ✍️ signed on the route: accepting nets away real obligations. */
    public function accept(Request $request, int $batchId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::NETTING_ACCEPT->value, $organizationId);

        $this->batchOrNotFound($batchId, $organizationId);

        $batch = $this->netting->accept($batchId, $organizationId, $this->userId($request));

        return ApiResponse::item(new NettingBatchResource(
            $batch,
            $this->query->positionIn($batchId, $organizationId),
        ));
    }

    public function reject(RejectNettingRequest $request, int $batchId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::NETTING_ACCEPT->value, $organizationId);

        $this->batchOrNotFound($batchId, $organizationId);

        $batch = $this->netting->reject(
            $batchId,
            $organizationId,
            (string) $request->validated('reason'),
            $this->userId($request),
        );

        return ApiResponse::item(new NettingBatchResource(
            $batch,
            $this->query->positionIn($batchId, $organizationId),
        ));
    }

    private function batchOrNotFound(int $batchId, int $organizationId): NettingBatchModel
    {
        return $this->query->nettingBatch($batchId, $organizationId) ?? throw $this->notFound();
    }
}
