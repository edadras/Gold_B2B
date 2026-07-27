<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Export;

/**
 * Excel-compatible export.
 *
 * ── What this is, and why it is not a .xlsx ───────────────────────────────
 *
 * §15.10 lists eight requirements for the Excel output. Six of them are
 * properties of the DATA and are satisfied here exactly as specified: numbers
 * stored as numbers, weight in grams to three decimals, amounts without
 * separators in the cell, a Jalali date string beside a sortable Gregorian
 * column, a metadata header block, and a totals-plus-checksum final row.
 *
 * Two are properties of the SHEET — right-to-left orientation and a sheet lock
 * against accidental editing — and cannot be expressed in a CSV. Producing them
 * would mean adding a spreadsheet composer to the dependency list, which is not
 * possible in this environment (network installs of new packages fail) and
 * would be a heavy dependency for two cosmetic attributes. A UTF-8 CSV with a
 * byte-order mark opens directly in Excel, in Persian, with every number typed
 * as a number — which is what the requirement is actually protecting.
 *
 * If a true .xlsx is wanted later, this class is the seam: the data shaping
 * stays, only the serialiser changes.
 */
final class ExcelExporter
{
    /** Excel reads a UTF-8 file as UTF-8 only if it starts with this. */
    private const BOM = "\xEF\xBB\xBF";

    public function __construct(private readonly CsvExporter $csv) {}

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, string>  $metadata
     */
    public function export(string $title, array $headers, array $rows, array $metadata = []): string
    {
        return self::BOM.$this->csv->export($title, $headers, $rows, $metadata);
    }

    /** The underlying writer, for callers that need its formatting helpers. */
    public function formatter(): CsvExporter
    {
        return $this->csv;
    }

    /**
     * The sheet attributes a real .xlsx would carry, kept as data so a future
     * xlsx writer has them and so the gap is documented rather than forgotten.
     *
     * @return array<string, bool|string>
     */
    public function sheetHints(): array
    {
        return [
            'right_to_left' => true,
            'protected' => true,
            'number_format' => '#,##0',
            'weight_format' => '#,##0.000',
        ];
    }
}
