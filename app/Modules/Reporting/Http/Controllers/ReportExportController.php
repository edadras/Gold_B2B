<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Application\ReportJobService;
use App\Modules\Reporting\Http\Requests\RequestReportExportRequest;
use App\Modules\Reporting\Http\Resources\ReportJobResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/reports/export`, `/reports/exports/{id}` and `/reports/download/{token}`
 * — §2.13.
 */
final class ReportExportController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly ReportJobService $jobs,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);

        return ApiResponse::collection(
            ReportJobResource::collection($this->jobs->recentForOrganization($organizationId)),
        );
    }

    /**
     * Ask for an export.
     *
     * §15.8's fork is reported in the status code, not buried in the body: a
     * light report runs inside the request and comes back 201 with its download
     * link already usable, a heavy one is recorded QUEUED and comes back 202
     * «پذیرفته شد، در حال پردازش» (§1.6) for the client to poll.
     */
    public function store(RequestReportExportRequest $request): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);

        $job = $this->jobs->request(
            organizationId: $organizationId,
            type: $request->reportType(),
            range: $request->range(),
            format: $request->format(),
            userId: $this->userId($request),
        );

        return ApiResponse::item(new ReportJobResource($job), $job->ran_inline ? 201 : 202);
    }

    /** Another organisation's job is 404, never 403 — see authorizeReporting(). */
    public function show(Request $request, int $jobId): JsonResponse
    {
        $organizationId = $this->authorizeReporting($request);

        $job = $this->jobs->findForOrganization($jobId, $organizationId);

        if ($job === null) {
            throw $this->notFound();
        }

        return ApiResponse::item(new ReportJobResource($job));
    }

    /**
     * Download a generated report.
     *
     * ─────────────────────────────────────────────────────────────────────────
     *  THIS ROUTE IS DELIBERATELY NOT BEHIND `auth:sanctum`.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * Two reasons, one practical and one structural.
     *
     * Structural: ReportJobService::signedUrl() hardcodes
     * `/api/v1/reports/download/{token}` and hands that string to the member as
     * the report's link. The path must therefore exist at exactly that spelling,
     * and the link has to work on its own — it is pasted into a browser, opened
     * from an e-mail, or fetched by a download manager that carries no bearer
     * token. A capability URL is the mechanism §15.8 chose
     * («لینک امضاشده … لینک موقت ۲۴ ساعت»).
     *
     * Practical: the token IS the credential. It is 64 hex characters of
     * `sha256(uuid + 16 random bytes)` — 256 bits of entropy — it names a file
     * whose storage path is derived from the token rather than from the job or
     * the organisation, and it is checked for expiry on every single read
     * (ReportJobModel::isDownloadable), not merely when the link was minted.
     *
     * THE TRADEOFF, STATED PLAINLY: anyone who obtains the URL can read the
     * report until the token expires. A bearer token in a header does not leak
     * through a Referer header, a browser history entry or a chat client; a URL
     * does. That is accepted here, bounded by three things: the 24-hour window,
     * the fact that revoking is a single UPDATE that nulls the token, and the
     * unguessability of the token itself, which makes enumeration hopeless (an
     * unknown, expired or foreign token is an indistinguishable 404). If the
     * exposure ever stops being acceptable, the fix is to require the bearer
     * token here and have the client stream the file — not to lengthen the
     * token.
     *
     * No permit() call: there is no authenticated caller to authorise. The
     * token alone decides, and it is scoped to exactly one file.
     */
    public function download(Request $request, string $token): Response
    {
        $job = $this->jobs->resolveToken($token);

        if ($job === null) {
            throw $this->notFound();
        }

        $content = $this->jobs->download($token);

        if ($content === null) {
            // The row says downloadable but the file is gone: still a 404, and
            // still indistinguishable from a bad token.
            throw $this->notFound();
        }

        $format = $job->formatEnum();

        return response($content, 200, [
            'Content-Type' => $format->mimeType(),
            'Content-Disposition' => sprintf(
                'attachment; filename="%s-%s-%s.%s"',
                strtolower($job->report_type),
                (string) $job->range_from,
                (string) $job->range_to,
                $format->extension(),
            ),
            'Content-Length' => (string) strlen($content),
            // A report is a snapshot of somebody's books; no shared cache may
            // keep a copy, and the browser must not serve it from disk after
            // the token expires.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * BOTH LEGS, EVERY TIME. `permit()` asks Identity's AuthorizationGateway,
     * which checks that the caller's roles grant `ledger.view` AND that the
     * organisation involved is the caller's own. Neither is sufficient by
     * itself: a role check alone would let any member export another member's
     * books by naming their id, and a tenant scope alone would not help either
     * — report generation runs raw aggregate queries, and a raw query or a
     * `withoutGlobalScope()` call walks straight past an Eloquent global scope.
     * Tenancy is therefore asserted explicitly here, against the organisation
     * id taken from the token.
     *
     * The job lookups then filter on that same organisation id inside the
     * service, so a job belonging to another member is not found at all and the
     * endpoint answers 404 rather than 403 — a 403 would confirm the job id
     * exists and turn `/reports/exports/{id}` into an id oracle.
     */
    private function authorizeReporting(Request $request): int
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::LEDGER_VIEW->value, $organizationId);

        return $organizationId;
    }
}
