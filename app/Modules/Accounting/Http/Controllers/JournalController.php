<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Application\JournalReader;
use App\Modules\Accounting\Http\Requests\AccountingRangeRequest;
use App\Modules\Accounting\Http\Resources\JournalEntryResource;
use App\Modules\Accounting\Http\Resources\JournalExportResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/accounting/journal` and `/accounting/export` — docs/05-api/02-endpoints.md
 * §2.13.
 */
final class JournalController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly JournalReader $journal,
    ) {
        parent::__construct($authorization);
    }

    /** «دفتر روزنامه» — vouchers with their lines, plus POSTED control totals. */
    public function index(AccountingRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeLedgerRead($request);

        $journal = $this->journal->entries($organizationId, $request->from(), $request->to());

        return ApiResponse::collection(
            JournalEntryResource::collection($journal['entries']),
            meta: [
                'range' => ['from' => $request->from(), 'to' => $request->to()],
                'totals' => $journal['totals'],
            ],
        );
    }

    /** «خروجی برای نرم‌افزار حسابداری» — one flat row per POSTED journal line. */
    public function export(AccountingRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeLedgerRead($request);

        $export = $this->journal->exportRows($organizationId, $request->from(), $request->to());

        return ApiResponse::item(
            new JournalExportResource($export, $request->from(), $request->to()),
        );
    }

    /**
     * BOTH LEGS OF AUTHORISATION ARE CHECKED HERE, AND NEITHER IS SUFFICIENT
     * ALONE.
     *
     * `permit()` delegates to Identity's AuthorizationGateway, which asserts
     * (1) that the caller's roles grant `ledger.view` — the journal is the
     * member's own books and an ACCOUNTANT, TREASURER or OWNER may read them,
     * a user whose roles were removed may not — and (2) that the organisation
     * whose journal is about to be read is the caller's own.
     *
     * The tenant leg has to be asserted here rather than left to a scope. The
     * journal reader joins `journal_lines` to `journal_entries` and filters on
     * `journal_entries.organization_id` explicitly; an Eloquent global scope
     * would not survive that join, and any raw query or a single
     * `withoutGlobalScope()` call bypasses a scope outright. Checking tenancy at
     * the boundary, against the organisation id taken from the authenticated
     * token, is the only version of the check that a future query cannot
     * accidentally opt out of.
     *
     * There is no id in the path for a caller to tamper with — the organisation
     * comes from the token — so a refusal here can only mean "your role does
     * not allow this", which is a 403. Cross-tenant *resources* elsewhere in the
     * API answer 404 instead, so that an id cannot be probed for existence.
     */
    private function authorizeLedgerRead(Request $request): int
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        return $organizationId;
    }
}
