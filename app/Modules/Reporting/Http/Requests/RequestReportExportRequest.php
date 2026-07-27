<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Reporting\Domain\ReportFormat;
use App\Modules\Reporting\Domain\ReportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /reports/export — §2.13 «درخواست خروجی (ناهمگام)». */
final class RequestReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'report_type' => ['required', Rule::in(array_map(
                static fn (ReportType $t): string => $t->value,
                ReportType::cases(),
            ))],
            'format' => ['nullable', Rule::in(array_map(
                static fn (ReportFormat $f): string => $f->value,
                ReportFormat::cases(),
            ))],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function reportType(): ReportType
    {
        return ReportType::from((string) $this->validated('report_type'));
    }

    public function format(): ReportFormat
    {
        $format = $this->validated('format');

        return is_string($format) ? ReportFormat::from($format) : ReportFormat::CSV;
    }

    /** Width is capped by DateRange itself — see ReportRangeRequest. */
    public function range(): DateRange
    {
        return DateRange::of(
            (string) $this->validated('from'),
            (string) $this->validated('to'),
        );
    }
}
