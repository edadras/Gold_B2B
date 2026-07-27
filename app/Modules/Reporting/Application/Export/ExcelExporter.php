<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Export;

/**
 * The .xlsx export of docs/03-domain/15-notification-reporting.md §15.10.
 *
 * This class shapes the sheet; XlsxWriter serialises it. The eight rules of
 * §15.10 and where each is satisfied:
 *
 *   اعداد به‌صورت عدد        XlsxWriter writes t="n" cells — a number a member
 *                            can sum, not a string that merely looks like one
 *   وزن به گرم با ۳ رقم      CsvExporter::grams() shapes it, numFmt 165 keeps
 *                            the trailing zeros visible
 *   مبلغ بدون جداکننده       the value is bare; numFmt 164 adds the separators
 *                            at display time only
 *   شمسی + میلادی            CsvExporter::dateColumns(), unchanged — the Jalali
 *                            column is what a member reads, the ISO one is what
 *                            a spreadsheet can sort by
 *   راست‌به‌چپ                 <sheetView rightToLeft="1"/>
 *   ردیف اول: عنوان و ...     the title and the metadata block, in bold
 *   ردیف آخر: جمع + checksum  a totals row over the numeric columns, then the
 *                            same SHA-256 the CSV export publishes
 *   قفل شیت                   <sheetProtection sheet="1"/>
 *
 * The CSV writer is still the export for anyone who wants a plain, diffable,
 * pipeline-friendly file — ReportFormat::CSV selects it and nothing here
 * replaces it. What changed is that ReportFormat::EXCEL now means a real
 * workbook rather than a CSV with a byte-order mark, because the two sheet
 * properties above are not expressible in a CSV at all and §15.10 asks for
 * them by name.
 *
 * The data shaping helpers still come from CsvExporter, deliberately: both
 * exports must agree on what 1,247,320 milligrams looks like and on what the
 * checksum covers, and that agreement only holds if there is one
 * implementation of each.
 */
final class ExcelExporter
{
    public function __construct(
        private readonly CsvExporter $csv,
        private readonly XlsxWriter $writer = new XlsxWriter,
    ) {}

    /**
     * A complete workbook, as a binary string.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, string>  $metadata  header block: label => value
     */
    public function export(string $title, array $headers, array $rows, array $metadata = []): string
    {
        /** @var array<int, array<int, mixed>> $sheet */
        $sheet = [];
        /** @var array<int, true> $bold */
        $bold = [];

        // §15.10 «ردیف اول: عنوان گزارش، دوره، تاریخ تولید، نام سازمان»
        $bold[count($sheet)] = true;
        $sheet[] = [$title];

        foreach ($metadata as $label => $value) {
            $sheet[] = [$label, $value];
        }

        $sheet[] = [];

        $bold[count($sheet)] = true;
        $sheet[] = array_values($headers);

        foreach ($rows as $row) {
            $sheet[] = array_values($row);
        }

        // §15.10 «ردیف آخر: جمع + checksum»
        $totals = $this->totals($headers, $rows);

        if ($totals !== null) {
            $bold[count($sheet)] = true;
            $sheet[] = $totals;
        }

        $sheet[] = ['checksum', $this->csv->checksum($rows)];

        return $this->writer->write($this->sheetName($title), $sheet, $bold);
    }

    /** The underlying formatter, for callers that need its shaping helpers. */
    public function formatter(): CsvExporter
    {
        return $this->csv;
    }

    /**
     * The sheet attributes this workbook carries, exposed so a caller (or a
     * test) can state what was asked for without reopening the file.
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

    /**
     * A totals row over every column whose data cells are all numeric.
     *
     * Summed with bcmath at the column's own scale, never with floats: a rial
     * column that has run past 2^53 must still add up, and a gram column that
     * says 248.750 must total to three decimals rather than to 248.74999.
     * Columns that hold dates, codes or labels are left blank — a total under
     * a column of trade ids would be arithmetic without meaning.
     *
     * Returns null when nothing in the report is summable, in which case the
     * row is omitted rather than printed empty.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @return ?array<int, mixed>
     */
    private function totals(array $headers, array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $width = count($headers);

        /** @var array<int, mixed> $row */
        $row = array_fill(0, max($width, 1), null);
        $row[0] = 'جمع';
        $summed = false;

        for ($column = 1; $column < $width; $column++) {
            $scale = 0;
            $total = '0';
            $sawNumber = false;
            $numericColumn = true;

            foreach ($rows as $data) {
                $cell = array_values($data)[$column] ?? null;

                if ($cell === null || $cell === '') {
                    continue;
                }

                $text = is_bool($cell) ? ($cell ? '1' : '0') : (string) $cell;

                if (preg_match('/^-?\d+(\.(\d+))?$/', $text, $match) !== 1) {
                    $numericColumn = false;

                    break;
                }

                $scale = max($scale, strlen($match[2] ?? ''));
                $total = bcadd($total, $text, 6);
                $sawNumber = true;
            }

            if (! $numericColumn || ! $sawNumber) {
                continue;
            }

            $formatted = bcadd($total, '0', $scale);
            $row[$column] = $scale === 0 ? (int) $formatted : $formatted;
            $summed = true;
        }

        return $summed ? $row : null;
    }

    /** Excel shows this on the tab; the report title is the obvious choice. */
    private function sheetName(string $title): string
    {
        return $title === '' ? 'Report' : $title;
    }
}
