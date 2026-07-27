<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Application\TrialBalanceService;
use App\Modules\Accounting\Http\Requests\AccountingRangeRequest;
use App\Modules\Accounting\Http\Resources\TrialBalanceResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /reports/trial-balance` — §2.13's «تراز آزمایشی».
 *
 * WHY THIS LIVES IN ACCOUNTING DESPITE THE /reports PREFIX.
 *
 * The documentation groups the trial balance with the other reports, and the
 * client-facing path keeps that grouping. The code cannot. A trial balance is
 * built by TrialBalanceService from `journal_entries` and `journal_lines`,
 * which are Accounting's tables and Accounting's invariants (§9.3's twin RIAL
 * and GOLD line sets, §9.7's POSTED-only rule). Reporting may depend on Shared
 * and Identity ONLY — tests/Architecture/ArchitectureTest.php enforces it — so
 * a controller in Reporting could not so much as name TrialBalanceService.
 *
 * The alternatives were worse: routing it through Reporting's
 * ReportingDataSource port would put a report-shaped method on an interface
 * that exists to carry facts, and duplicating the aggregation in Reporting
 * would give the platform two trial balances that could disagree — which is
 * precisely the failure a trial balance exists to detect.
 *
 * So the route is declared in Accounting's routes.php at the path §2.13 gives
 * it. The URL is part of the contract; which module serves it is not.
 */
final class TrialBalanceController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly TrialBalanceService $trialBalance,
    ) {
        parent::__construct($authorization);
    }

    public function show(AccountingRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeLedgerRead($request);

        return ApiResponse::item(new TrialBalanceResource(
            $this->trialBalance->build($organizationId, $request->from(), $request->to()),
        ));
    }

    /**
     * BOTH LEGS, CHECKED TOGETHER. `permit()` asks Identity's
     * AuthorizationGateway to confirm that the caller's roles grant
     * `ledger.view` AND that the organisation being reported on is the caller's
     * own. Either leg alone leaves a hole: a role check by itself would let one
     * member pull another's trial balance, and a tenancy scope by itself is not
     * dependable here because TrialBalanceService aggregates through a raw
     * `DB::table()` join, which no Eloquent global scope touches — and even on
     * a model, `withoutGlobalScope()` removes it. Tenancy is therefore
     * re-asserted explicitly at the boundary against the token's organisation.
     */
    private function authorizeLedgerRead(Request $request): int
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        return $organizationId;
    }
}
