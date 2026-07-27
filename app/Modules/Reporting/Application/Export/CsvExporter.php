<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Export;

use App\Modules\Shared\Support\JalaliDate;

/**
 * CSV writer for report exports, following the rules of §15.10.
 *
 * The rules that actually change the output, and why:
 *
 *  · **Numbers stay numbers.** «اعداد به‌صورت عدد ذخیره شوند، نه رشته (تا قابل
 *    جمع باشند)». An int is written bare — no quotes, no thousands separators,
 *    no leading apostrophe — so a spreadsheet types the cell as a number and
 *    the member can sum a column. Formatting a rial amount as "19,521,900,000"
 *    would turn the whole column into text.
 *
 *  · **Weight in grams to three decimals.** «وزن به گرم با ۳ رقم اعشار».
 *    Milligrams are converted by string manipulation, never by dividing —
 *    1247320 becomes "1247.320" without a float ever existing. Trailing zeros
 *    are kept because three decimals is the contract, and "1247.32" would be a
 *    different number to a careless reader.
 *
 *  · **Two date columns.** «تاریخ شمسی به‌صورت رشته + یک ستون تاریخ میلادی برای
 *    مرتب‌سازی». The Jalali column is what a member reads, but no spreadsheet
 *    parses it as a date, so it cannot be sorted chronologically, filtered by
 *    month, or subtracted. The ISO Gregorian column beside it is what makes all
 *    of that work on the exported file.
 *
 *  · **A checksum row at the end.** «ردیف آخر: جمع + checksum». It covers the
 *    data rows only, so re-exporting the same period yields the same digest
 *    regardless of when the header says it was generated.
 *
 * Written by hand rather than with a spreadsheet library: installing a new
 * composer package is not possible in this environment, and everything on the
 * §15.10 list except two cosmetic sheet properties is a property of the data.
 */
final class CsvExporter
{
    private const DELIMITER = ',';

    private const NEWLINE = "\r\n";   // what Excel expects

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, string>  $metadata  header block: label => value
     */
    public function export(string $title, array $headers, array $rows, array $metadata = []): string
    {
        $out = '';

        // §15.10 «ردیف اول: عنوان گزارش، دوره، تاریخ تولید، نام سازمان»
        $out .= $this->line([$title]);

        foreach ($metadata as $label => $value) {
            $out .= $this->line([$label, $value]);
        }

        $out .= self::NEWLINE;
        $out .= $this->line($headers);

        foreach ($rows as $row) {
            $out .= $this->line($row);
        }

        $out .= $this->line(['checksum', $this->checksum($rows)]);

        return $out;
    }

    /**
     * SHA-256 over the data rows, cells joined with a unit separator so that
     * ["a,b"] and ["a","b"] cannot produce the same digest.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function checksum(array $rows): string
    {
        $canonical = '';

        foreach ($rows as $row) {
            $canonical .= implode("\x1f", array_map(
                fn (mixed $cell): string => $this->canonicalise($cell),
                $row,
            ))."\x1e";
        }

        return hash('sha256', $canonical);
    }

    /**
     * Milligrams as grams with exactly three decimals, without floating point.
     *
     * 1_247_320 → "1247.320"; -500 → "-0.500"; 0 → "0.000".
     */
    public function grams(int $milligrams): string
    {
        $sign = $milligrams < 0 ? '-' : '';
        $absolute = abs($milligrams);

        return sprintf('%s%d.%03d', $sign, intdiv($absolute, 1000), $absolute % 1000);
    }

    /** Purity ×10,000 as its conventional per-mille form: 9950 → "995.0". */
    public function purity(int $purityX10k): string
    {
        return sprintf('%d.%d', intdiv($purityX10k, 10), $purityX10k % 10);
    }

    /** The Jalali rendering of a Y-m-d date, for the human-readable column. */
    public function jalali(string $gregorianDate): string
    {
        return JalaliDate::fromGregorianString($gregorianDate)->format('/');
    }

    /**
     * The paired date columns of §15.10, in the order they should appear.
     *
     * @return array{0: string, 1: string} [Jalali, Gregorian]
     */
    public function dateColumns(string $gregorianDate): array
    {
        return [$this->jalali($gregorianDate), substr($gregorianDate, 0, 10)];
    }

    /** @param array<int, mixed> $cells */
    private function line(array $cells): string
    {
        return implode(self::DELIMITER, array_map(
            fn (mixed $cell): string => $this->cell($cell),
            $cells,
        )).self::NEWLINE;
    }

    /**
     * One cell.
     *
     * Integers are emitted bare so they stay numeric. Everything else is quoted
     * only when it has to be — a quoted numeric string is exactly the failure
     * §15.10 warns about, so the two cases are kept strictly apart.
     */
    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = (string) $value;

        // A decimal produced by grams() or purity() is still a number.
        if (preg_match('/^-?\d+(\.\d+)?$/', $string) === 1) {
            return $string;
        }

        if (
            str_contains($string, self::DELIMITER)
            || str_contains($string, '"')
            || str_contains($string, "\n")
            || str_contains($string, "\r")
        ) {
            return '"'.str_replace('"', '""', $string).'"';
        }

        return $string;
    }

    private function canonicalise(mixed $cell): string
    {
        return match (true) {
            $cell === null => '',
            is_bool($cell) => $cell ? '1' : '0',
            default => (string) $cell,
        };
    }
}
