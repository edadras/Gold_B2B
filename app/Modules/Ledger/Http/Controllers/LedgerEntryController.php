<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Ledger\Application\BalanceQueryService;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Http\Requests\LedgerIndexRequest;
use App\Modules\Ledger\Http\Resources\LedgerEntryResource;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntryModel;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Support\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /ledger/gold`, `/ledger/rial`, `/ledger/entries/{id}` — §2.3. */
final class LedgerEntryController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly BalanceQueryService $ledger,
    ) {
        parent::__construct($authorization);
    }

    public function gold(LedgerIndexRequest $request): JsonResponse
    {
        return $this->page($request, AssetType::GOLD);
    }

    public function rial(LedgerIndexRequest $request): JsonResponse
    {
        return $this->page($request, AssetType::RIAL);
    }

    public function show(Request $request, int $entryId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Role leg first, then tenancy: the lookup itself is scoped to the
        // caller's organisation, so an entry belonging to another member comes
        // back null and is reported as 404. Answering 403 would confirm the id
        // exists and turn this into an oracle over the whole ledger.
        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        $entry = $this->ledger->entry($entryId, $organizationId);

        if ($entry === null) {
            throw $this->notFound();
        }

        return ApiResponse::item(new LedgerEntryResource($entry));
    }

    private function page(LedgerIndexRequest $request, AssetType $asset): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        $limit = Cursor::limit($request);

        $entries = $this->ledger->entries(
            organizationId: $organizationId,
            asset: $asset,
            bucket: $request->bucket(),
            beforeId: Cursor::afterId($request),
            limit: $limit,
        );

        $ids = $entries->map(static fn (LedgerEntryModel $e): int => (int) $e->id)->all();

        return ApiResponse::collection(
            LedgerEntryResource::collection($entries),
            Cursor::links($request, $ids, $limit),
        );
    }

    /** @return list<string> */
    public static function bucketValues(): array
    {
        return array_map(static fn (Bucket $b): string => $b->value, Bucket::cases());
    }
}
