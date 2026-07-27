<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Application\DailyProfitReader;
use App\Modules\Reporting\Application\FeeReport;
use App\Modules\Reporting\Application\GoldFlowReport;
use App\Modules\Reporting\Application\InventoryReport;
use App\Modules\Reporting\Application\PnlReport;
use App\Modules\Reporting\Application\RialFlowReport;
use App\Modules\Reporting\Application\TradeReport;
use App\Modules\Reporting\Http\Requests\ReportRangeRequest;
use App\Modules\Reporting\Http\Resources\DailyProfitResource;
use App\Modules\Reporting\Http\Resources\FeeReportResource;
use App\Modules\Reporting\Http\Resources\FlowReportResource;
use App\Modules\Reporting\Http\Resources\InventoryReportResource;
use App\Modules\Reporting\Http\Resources\PnlReportResource;
use App\Modules\Reporting\Http\Resources\TradeReportResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/reports/*` — docs/05-api/02-endpoints.md §2.13, the member reports.
 *
 * `/reports/trial-balance` is NOT here: it is built by Accounting's
 * TrialBalanceService and Reporting is not permitted to import Accounting
 * (tests/Architecture/ArchitectureTest.php: Reporting may depend on Shared and
 * Identity only). It lives in Accounting's own routes file, at the /reports
 * path the documentation gives it.
 *
 * Every action takes the caller's own organisation from the token and never
 * from a parameter, so there is no id here to tamper with. The one-year range
 * cap is enforced by DateRange's constructor — see ReportRangeRequest.
 */
final class ReportController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly GoldFlowReport $goldFlow,
        private readonly RialFlowReport $rialFlow,
        private readonly TradeReport $trades,
        private readonly PnlReport $pnl,
        private readonly InventoryReport $inventory,
        private readonly FeeReport $fees,
        private readonly DailyProfitReader $dailyProfit,
    ) {
        parent::__construct($authorization);
    }

    public function goldFlow(ReportRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);
        $range = $request->range();

        $report = $this->goldFlow->build($organizationId, $range);

        return ApiResponse::item(new FlowReportResource($report, $this->goldFlow->lines($report)));
    }

    public function rialFlow(ReportRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);
        $range = $request->range();

        $report = $this->rialFlow->build($organizationId, $range);

        return ApiResponse::item(new FlowReportResource($report, $this->rialFlow->lines($report)));
    }

    public function trades(ReportRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);
        $range = $request->range();

        return ApiResponse::item(new TradeReportResource($this->trades->build($organizationId, $range), $range));
    }

    public function pnl(ReportRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);
        $range = $request->range();

        return ApiResponse::item(new PnlReportResource($this->pnl->build($organizationId, $range), $range));
    }

    /** Instantaneous — no range, hence a plain Request rather than the range one. */
    public function inventory(Request $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);

        return ApiResponse::item(new InventoryReportResource($this->inventory->build($organizationId)));
    }

    public function fees(ReportRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);
        $range = $request->range();

        return ApiResponse::item(new FeeReportResource($this->fees->build($organizationId, $range), $range));
    }

    public function dailyProfit(ReportRangeRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);
        $range = $request->range();

        return ApiResponse::item(
            new DailyProfitResource($this->dailyProfit->forRange($organizationId, $range), $range),
        );
    }

    /**
     * BOTH LEGS OF AUTHORISATION, IN ONE PLACE BECAUSE EVERY ACTION NEEDS THEM.
     *
     * `permit()` asks Identity's AuthorizationGateway, which checks (1) that the
     * caller's roles grant `ledger.view`, and (2) that the organisation the
     * report will be built for is the caller's own. Neither leg is sufficient
     * alone:
     *
     *   · a role check by itself would let any TRADER pull another member's
     *     gold flow simply by naming their organisation id;
     *   · a tenant scope by itself is not enough either, and that is the subtle
     *     one — every query behind these reports takes an explicit
     *     `organization_id`, and an Eloquent global scope (had one been relied
     *     on) is bypassed by `withoutGlobalScope()` or by any raw query, which
     *     is exactly what the reporting data sources use. Tenancy is therefore
     *     re-asserted here, at the boundary, rather than trusted to the model.
     *
     * The organisation id is read from the authenticated token, so the two legs
     * are checked against the same value the query will use — there is no
     * window between the check and the use for a parameter to change it.
     *
     * A denial is a 403 FORBIDDEN_ROLE (the caller's own organisation exists and
     * is not being hidden). Cross-tenant *resources* — an export job belonging
     * to somebody else — are 404 instead; see ReportExportController.
     */
    private function authorizeReporting(Request $request): int
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        return $organizationId;
    }
}
