<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

enum ReportFormat: string
{
    /**
     * Plain UTF-8 CSV.
     *
     * Kept as a first-class option, not a fallback: it is the format that
     * diffs, streams, pipes into another system and opens in anything. Every
     * §15.10 rule that is a property of the DATA holds here too — numbers stay
     * numbers, weights carry three decimals, both date columns are present.
     */
    case CSV = 'CSV';

    /**
     * A real .xlsx workbook (§15.10).
     *
     * The two requirements a CSV cannot express — «راست‌به‌چپ بودن شیت» and
     * «قفل شیت» — are properties of the sheet, so this format produces an
     * actual SpreadsheetML package. See XlsxWriter for why it is written by
     * hand rather than pulled in as a dependency.
     */
    case EXCEL = 'EXCEL';

    case JSON = 'JSON';

    public function extension(): string
    {
        return match ($this) {
            self::CSV => 'csv',
            self::EXCEL => 'xlsx',
            self::JSON => 'json',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv; charset=UTF-8',
            self::EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::JSON => 'application/json',
        };
    }

    /**
     * Whether the payload is a binary package rather than text.
     *
     * A caller that streams the file must not touch a workbook's bytes; the
     * CSV and JSON exports are text and may safely be transcoded.
     */
    public function isBinary(): bool
    {
        return $this === self::EXCEL;
    }

    /**
     * A UTF-8 CSV needs a byte-order mark for Excel to read Persian correctly.
     * A workbook does not: each of its XML parts declares its own encoding, and
     * a BOM prepended to a ZIP would simply corrupt it.
     */
    public function needsByteOrderMark(): bool
    {
        return false;
    }
}
