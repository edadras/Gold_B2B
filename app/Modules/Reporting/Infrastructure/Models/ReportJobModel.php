<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Reporting\Domain\ReportFormat;
use App\Modules\Reporting\Domain\ReportJobStatus;
use App\Modules\Reporting\Domain\ReportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $report_type
 * @property string $format
 * @property string $status
 * @property ?string $download_token
 * @property ?string $file_path
 */
final class ReportJobModel extends Model
{
    protected $table = 'report_jobs';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'int',
        'requested_by_user_id' => 'int',
        'parameters' => 'array',
        'ran_inline' => 'bool',
        'estimated_rows' => 'int',
        'row_count' => 'int',
        'file_size' => 'int',
        'token_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function statusEnum(): ReportJobStatus
    {
        return ReportJobStatus::from($this->status);
    }

    public function typeEnum(): ReportType
    {
        return ReportType::from($this->report_type);
    }

    public function formatEnum(): ReportFormat
    {
        return ReportFormat::from($this->format);
    }

    /**
     * Whether the signed link is still good.
     *
     * Expiry is checked here, on every read, rather than only when the link is
     * minted: a token that outlives its window is the whole risk of handing out
     * a URL that needs no further authentication.
     */
    public function isDownloadable(?Carbon $now = null): bool
    {
        if (! $this->statusEnum()->isDownloadable()) {
            return false;
        }

        if ($this->download_token === null || $this->token_expires_at === null) {
            return false;
        }

        return $this->token_expires_at->greaterThan($now ?? Carbon::now());
    }
}
