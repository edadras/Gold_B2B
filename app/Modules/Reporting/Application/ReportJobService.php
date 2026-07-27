<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Application\Export\CsvExporter;
use App\Modules\Reporting\Application\Export\ExcelExporter;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Reporting\Domain\ReportFormat;
use App\Modules\Reporting\Domain\ReportJobStatus;
use App\Modules\Reporting\Domain\ReportType;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The request → run → download path of §15.8.
 *
 *      «آیا سبک است؟ (< 1000 رکورد، < 2 ثانیه)»
 *          بله ► اجرای فوری
 *          خیر ► صف reporting ► ذخیره در S3 ► لینک موقت (۲۴ ساعت)
 *
 * Three rules from that section are enforced here rather than left to callers:
 *
 *  · **One year maximum per request.** Enforced by DateRange's constructor, so
 *    there is no way to reach this service with a wider range.
 *  · **A random file name and a signed link.** The stored path is derived from
 *    a 64-character random token, never from the job id, so knowing that job
 *    1234 exists tells an attacker nothing about where its file lives or what
 *    another member's file is called.
 *  · **A link that expires.** The token carries its own expiry and it is
 *    checked on every download attempt, not merely set when the link is minted.
 */
final readonly class ReportJobService
{
    public function __construct(
        private ReportingDataSource $data,
        private GoldFlowReport $goldFlow,
        private RialFlowReport $rialFlow,
        private TradeReport $trades,
        private PnlReport $pnl,
        private InventoryReport $inventory,
        private FeeReport $fees,
        private CsvExporter $csv,
        private ExcelExporter $excel,
    ) {}

    /**
     * Ask for a report.
     *
     * A light one is produced immediately and its job row comes back COMPLETED.
     * A heavy one is recorded as QUEUED for a worker to pick up via run().
     *
     * @throws \App\Modules\Shared\Exceptions\LimitExceededException when the range exceeds one year
     */
    public function request(
        int $organizationId,
        ReportType $type,
        DateRange $range,
        ReportFormat $format = ReportFormat::CSV,
        ?int $userId = null,
    ): ReportJobModel {
        $estimate = $this->estimateRows($type, $range);
        $inline = $estimate < $this->inlineRowThreshold() && $range->isNarrow();

        /** @var ReportJobModel $job */
        $job = ReportJobModel::query()->create([
            'organization_id' => $organizationId,
            'requested_by_user_id' => $userId,
            'report_type' => $type->value,
            'format' => $format->value,
            'range_from' => $range->from,
            'range_to' => $range->to,
            'parameters' => ['range' => $range->jsonSerialize()],
            'status' => ReportJobStatus::QUEUED->value,
            'ran_inline' => $inline,
            'estimated_rows' => $estimate,
        ]);

        return $inline ? $this->run($job) : $job;
    }

    /**
     * Generate the file for a queued job.
     *
     * Safe to call on a job that has already completed: it is returned
     * untouched rather than regenerated, so a redelivered queue message cannot
     * mint a second token for the same report.
     */
    public function run(ReportJobModel $job): ReportJobModel
    {
        if ($job->statusEnum() === ReportJobStatus::COMPLETED) {
            return $job;
        }

        $job->update([
            'status' => ReportJobStatus::RUNNING->value,
            'started_at' => Carbon::now(),
        ]);

        try {
            $range = new DateRange((string) $job->range_from, (string) $job->range_to);

            [$headers, $rows, $metadata] = $this->render($job, $range);

            $content = $job->formatEnum() === ReportFormat::EXCEL
                ? $this->excel->export($job->typeEnum()->label(), $headers, $rows, $metadata)
                : $this->csv->export($job->typeEnum()->label(), $headers, $rows, $metadata);

            $token = $this->mintToken();
            $path = $this->pathFor($job, $token);

            Storage::disk($this->disk())->put($path, $content);

            $job->update([
                'status' => ReportJobStatus::COMPLETED->value,
                'row_count' => count($rows),
                'file_path' => $path,
                'file_size' => strlen($content),
                'checksum' => $this->csv->checksum($rows),
                'download_token' => $token,
                'token_expires_at' => Carbon::now()->addHours($this->linkTtlHours()),
                'completed_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => ReportJobStatus::FAILED->value,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'completed_at' => Carbon::now(),
            ]);

            throw $e;
        }

        return $job->refresh();
    }

    /**
     * Resolve a download token to its job.
     *
     * Returns null for an unknown, expired or unfinished token — the caller
     * cannot tell which, which is the point: a probe learns nothing.
     */
    public function resolveToken(string $token, ?Carbon $now = null): ?ReportJobModel
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        /** @var ?ReportJobModel $job */
        $job = ReportJobModel::query()->where('download_token', $token)->first();

        if ($job === null || ! $job->isDownloadable($now)) {
            return null;
        }

        return $job;
    }

    /** The stored file, or null when the link is no longer good. */
    public function download(string $token, ?Carbon $now = null): ?string
    {
        $job = $this->resolveToken($token, $now);

        if ($job === null || $job->file_path === null) {
            return null;
        }

        $disk = Storage::disk($this->disk());

        return $disk->exists($job->file_path) ? (string) $disk->get($job->file_path) : null;
    }

    /** The link handed to the member — path plus token, no credentials. */
    public function signedUrl(ReportJobModel $job): string
    {
        if (! $job->isDownloadable()) {
            throw new OperationNotPermittedException('This report has no valid download link');
        }

        return sprintf('/api/v1/reports/download/%s', (string) $job->download_token);
    }

    /** Retire links that have run out, so tokens do not accumulate for ever. */
    public function expireStaleLinks(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return ReportJobModel::query()
            ->where('status', ReportJobStatus::COMPLETED->value)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $now)
            ->update([
                'status' => ReportJobStatus::EXPIRED->value,
                'download_token' => null,
            ]);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<string, string>}
     */
    private function render(ReportJobModel $job, DateRange $range): array
    {
        $organizationId = $job->organization_id;

        $metadata = [
            'سازمان' => $this->data->organizationName($organizationId) ?? (string) $organizationId,
            'دوره' => $this->csv->jalali($range->from).' تا '.$this->csv->jalali($range->to),
            'دوره (میلادی)' => $range->from.' تا '.$range->to,
            'تاریخ تولید' => Carbon::now()->toIso8601String(),
        ];

        return match ($job->typeEnum()) {
            ReportType::GOLD_FLOW => $this->renderGoldFlow($organizationId, $range, $metadata),
            ReportType::RIAL_FLOW => $this->renderRialFlow($organizationId, $range, $metadata),
            ReportType::TRADES => $this->renderTrades($organizationId, $range, $metadata),
            ReportType::PNL => $this->renderPnl($organizationId, $range, $metadata),
            ReportType::INVENTORY => $this->renderInventory($organizationId, $metadata),
            ReportType::FEES => $this->renderFees($organizationId, $range, $metadata),
            ReportType::DAILY_SUMMARY => $this->renderTrades($organizationId, $range, $metadata),
        };
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<string, string>}
     */
    private function renderGoldFlow(int $organizationId, DateRange $range, array $metadata): array
    {
        $report = $this->goldFlow->build($organizationId, $range);

        $rows = [];

        foreach ($this->goldFlow->lines($report) as $line) {
            $rows[] = [$line['label'], $this->csv->grams($line['weight_mg']), $line['weight_mg']];
        }

        // §15.7's mandatory reconciliation, carried into the file itself so an
        // exported report cannot be separated from its own warning.
        $rows[] = ['تطبیق با دفتر کل', $this->csv->grams($report->independentClosing), $report->independentClosing];
        $rows[] = ['اختلاف تطبیق', $this->csv->grams($report->discrepancy()), $report->discrepancy()];
        $rows[] = ['وضعیت تطبیق', $report->isReconciled() ? 'تطبیق شد' : ($report->warning() ?? 'مغایرت'), 0];

        return [['شرح', 'گرم خالص', 'میلی‌گرم'], $rows, $metadata];
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<string, string>}
     */
    private function renderRialFlow(int $organizationId, DateRange $range, array $metadata): array
    {
        $report = $this->rialFlow->build($organizationId, $range);

        $rows = [];

        foreach ($this->rialFlow->lines($report) as $line) {
            $rows[] = [$line['label'], $line['rial']];
        }

        $rows[] = ['تطبیق با دفتر کل', $report->independentClosing];
        $rows[] = ['اختلاف تطبیق', $report->discrepancy()];
        $rows[] = ['وضعیت تطبیق', $report->isReconciled() ? 0 : 1];

        return [['شرح', 'ریال'], $rows, $metadata];
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<string, string>}
     */
    private function renderTrades(int $organizationId, DateRange $range, array $metadata): array
    {
        $report = $this->trades->build($organizationId, $range);

        $rows = [];

        foreach ($report['rows'] as $trade) {
            [$jalali, $gregorian] = $this->csv->dateColumns($trade->executedOn);

            $rows[] = [
                $trade->tradeId,
                $jalali,
                $gregorian,
                $trade->side,
                $trade->venue,
                $this->csv->grams($trade->fineMg),
                $this->csv->purity($trade->purityX10k),
                $trade->pricePerFineGram,
                $trade->grossRial,
                $trade->feeRial,
                $trade->counterpartyName ?? $trade->counterpartyOrgId,
            ];
        }

        return [
            [
                'شناسه معامله', 'تاریخ شمسی', 'تاریخ میلادی', 'سمت', 'بازار',
                'وزن خالص (گرم)', 'عیار', 'قیمت هر گرم', 'مبلغ ناخالص', 'کارمزد', 'طرف مقابل',
            ],
            $rows,
            $metadata,
        ];
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<string, string>}
     */
    private function renderPnl(int $organizationId, DateRange $range, array $metadata): array
    {
        $report = $this->pnl->build($organizationId, $range);

        $rows = [];

        foreach ($report['statement'] as $line) {
            $rows[] = [$line['label'], $line['rial']];
        }

        foreach ($report['informational'] as $line) {
            $rows[] = ['(اطلاعاتی) '.$line['label'], $line['available'] ? $line['rial'] : null];
        }

        return [['شرح', 'ریال'], $rows, $metadata];
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<string, string>}
     */
    private function renderInventory(int $organizationId, array $metadata): array
    {
        $report = $this->inventory->build($organizationId);

        $rows = [];

        foreach ($report['rows'] as $lot) {
            $rows[] = [
                $lot->lotCode,
                $this->csv->grams($lot->grossMg),
                $this->csv->grams($lot->fineMg),
                $this->csv->purity($lot->purityX10k),
                $lot->status,
                $lot->location,
                $lot->bookValueRial,
                $lot->marketValueRial,
            ];
        }

        return [
            ['کد lot', 'وزن ناخالص (گرم)', 'وزن خالص (گرم)', 'عیار', 'وضعیت', 'محل', 'ارزش دفتری', 'ارزش روز'],
            $rows,
            $metadata,
        ];
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<string, string>}
     */
    private function renderFees(int $organizationId, DateRange $range, array $metadata): array
    {
        $report = $this->fees->build($organizationId, $range);

        $rows = [];

        foreach ($report['rows'] as $fee) {
            [$jalali, $gregorian] = $this->csv->dateColumns($fee->chargedOn);

            $rows[] = [$jalali, $gregorian, $fee->category, $fee->amountRial, $fee->description];
        }

        return [['تاریخ شمسی', 'تاریخ میلادی', 'نوع', 'مبلغ (ریال)', 'شرح'], $rows, $metadata];
    }

    private function estimateRows(ReportType $type, DateRange $range): int
    {
        return $type->estimatedRowsPerDay() * $range->days();
    }

    private function mintToken(): string
    {
        return hash('sha256', Str::uuid()->toString().random_bytes(16));
    }

    private function pathFor(ReportJobModel $job, string $token): string
    {
        // The random token is the file name: nothing about the path can be
        // guessed from the job id or the organisation.
        return sprintf('reports/%s/%s.%s', substr($token, 0, 2), $token, $job->formatEnum()->extension());
    }

    private function disk(): string
    {
        return (string) config('goldb2b.reporting.disk', 'local');
    }

    private function inlineRowThreshold(): int
    {
        return (int) config('goldb2b.reporting.inline_row_threshold', 1_000);
    }

    private function linkTtlHours(): int
    {
        return (int) config('goldb2b.reporting.link_ttl_hours', 24);
    }
}
