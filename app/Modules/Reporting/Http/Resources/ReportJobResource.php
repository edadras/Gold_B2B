<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * An export job — `POST /reports/export` and `GET /reports/exports/{id}`.
 *
 * `download_url` appears only while the link is actually good: the token
 * carries its own expiry and ReportJobModel::isDownloadable() checks it on
 * every read, so a job whose window has closed reports no URL rather than a URL
 * that 404s. `file_path` is never exposed — the storage layout is not the
 * client's business and the path contains the secret.
 *
 * @mixin ReportJobModel
 */
final class ReportJobResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ReportJobModel $job */
        $job = $this->resource;

        $downloadable = $job->isDownloadable();

        return [
            'id' => (int) $job->id,
            'report_type' => $job->report_type,
            'format' => $job->format,
            'status' => $job->status,
            'range' => ['from' => (string) $job->range_from, 'to' => (string) $job->range_to],
            'ran_inline' => (bool) $job->ran_inline,
            'estimated_rows' => (int) $job->estimated_rows,
            'row_count' => (int) $job->row_count,
            'file_size' => $job->file_size === null ? null : (int) $job->file_size,
            'checksum' => $job->checksum,
            'is_downloadable' => $downloadable,
            'download_url' => $downloadable
                ? sprintf('/api/v1/reports/download/%s', (string) $job->download_token)
                : null,
            'token_expires_at' => Display::iso($job->token_expires_at),
            'error' => $job->error,
            'started_at' => Display::iso($job->started_at),
            'completed_at' => Display::iso($job->completed_at),
            'created_at' => Display::iso($job->created_at),
        ] + $this->display($request, [
            'report_type_display' => $job->typeEnum()->label(),
            'created_at_jalali' => Display::jalali($job->created_at),
            'completed_at_jalali' => Display::jalali($job->completed_at),
            'token_expires_at_jalali' => Display::jalali($job->token_expires_at),
        ]);
    }
}
