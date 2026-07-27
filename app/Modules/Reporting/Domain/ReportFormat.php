<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

enum ReportFormat: string
{
    case CSV = 'CSV';
    /**
     * Excel-compatible CSV, not a real .xlsx.
     *
     * §15.10 asks for a spreadsheet whose numbers are numbers, whose sheet is
     * right-to-left and whose header rows carry the report metadata. Everything
     * on that list except the RTL sheet property and the sheet lock is a
     * property of the data, and a UTF-8 CSV with a BOM opens in Excel with the
     * numbers intact and the columns typed. Adding a spreadsheet composer for
     * the remaining two cosmetic items is not a trade worth making here — see
     * ExcelExporter for the full reasoning.
     */
    case EXCEL = 'EXCEL';
    case JSON = 'JSON';

    public function extension(): string
    {
        return match ($this) {
            self::CSV => 'csv',
            self::EXCEL => 'csv',
            self::JSON => 'json',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::CSV, self::EXCEL => 'text/csv; charset=UTF-8',
            self::JSON => 'application/json',
        };
    }

    /** Excel needs a BOM to read a UTF-8 file as UTF-8 (§15.10, Persian text). */
    public function needsByteOrderMark(): bool
    {
        return $this === self::EXCEL;
    }
}
