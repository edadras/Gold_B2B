<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Export;

use RuntimeException;
use ZipArchive;

/**
 * A minimal SpreadsheetML (.xlsx) writer.
 *
 * ── Why this is written by hand ───────────────────────────────────────────
 *
 * §15.10 asks for two things a CSV cannot carry — a right-to-left sheet and a
 * sheet lock — and `composer require` of a spreadsheet library fails in this
 * environment (the package proxy refuses GitHub auth). An .xlsx is a ZIP of
 * XML parts, and PHP ships both ZipArchive and the string handling needed to
 * emit them, so the whole dependency reduces to the seven parts below:
 *
 *   [Content_Types].xml         what each part in the package is
 *   _rels/.rels                 package → workbook
 *   xl/workbook.xml             the sheet list
 *   xl/_rels/workbook.xml.rels  workbook → worksheet, styles, shared strings
 *   xl/worksheets/sheet1.xml    the cells, the RTL view and the lock
 *   xl/sharedStrings.xml        the string pool every text cell points into
 *   xl/styles.xml               number formats: #,##0 for rial, 0.000 for grams
 *
 * ── The one rule that matters ─────────────────────────────────────────────
 *
 * «اعداد به‌صورت عدد ذخیره شوند، نه رشته (تا قابل جمع باشند)». A numeric cell
 * is written as `<c t="n"><v>19521900000</v></c>` — a bare number in the value
 * element, typed as a number, with the thousands separators living in the cell
 * FORMAT rather than in the data. A number written as an inline or shared
 * string looks identical on screen and cannot be summed, which is precisely
 * the failure the requirement exists to prevent.
 *
 * ── What it deliberately does not do ──────────────────────────────────────
 *
 * No formulas, no merged cells, no charts, no date serials (§15.10 wants a
 * Jalali string beside an ISO string, not an Excel date), no streaming for
 * huge sheets. Everything is assembled in memory, which is bounded by the
 * one-year range limit DateRange already enforces.
 */
final class XlsxWriter
{
    /** cellXfs indexes, in the order styles.xml declares them. */
    public const STYLE_DEFAULT = 0;

    public const STYLE_BOLD = 1;

    public const STYLE_INTEGER = 2;

    public const STYLE_DECIMAL = 3;

    private const XMLNS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const XMLNS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Excel refuses a sheet name longer than this, or containing []:*?/\ */
    private const MAX_SHEET_NAME = 31;

    /**
     * Build the package and return it as a binary string.
     *
     * @param  array<int, array<int, mixed>>  $rows  one array per sheet row
     * @param  array<int, true>  $boldRows  row indexes rendered in bold
     */
    public function write(string $sheetName, array $rows, array $boldRows = []): string
    {
        $strings = [];
        $sheet = $this->sheetXml($rows, $boldRows, $strings);

        $parts = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->packageRelsXml(),
            'xl/workbook.xml' => $this->workbookXml($sheetName),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelsXml(),
            'xl/worksheets/sheet1.xml' => $sheet,
            'xl/sharedStrings.xml' => $this->sharedStringsXml($strings),
            'xl/styles.xml' => $this->stylesXml(),
        ];

        return $this->zip($parts);
    }

    /** Excel's A, B, ... Z, AA, AB, ... column names. */
    public static function columnName(int $zeroBasedIndex): string
    {
        $name = '';
        $index = $zeroBasedIndex;

        do {
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    // ── the worksheet ────────────────────────────────────────────────────────

    /**
     * The sheet, with the two attributes §15.10 asks for and a CSV cannot give.
     *
     *   <sheetView rightToLeft="1"/>  — «راست‌به‌چپ بودن شیت». Column A sits on
     *   the right, which is what a Persian report has to look like; without it
     *   every heading reads backwards against its data.
     *
     *   <sheetProtection sheet="1"/>  — «قفل شیت برای جلوگیری از ویرایش
     *   تصادفی». A protection with no password: this is a guard rail against
     *   an accidental keystroke in a figure someone is about to file, not a
     *   security control, and pretending otherwise would be worse than useless.
     *
     * Element order is fixed by the schema — sheetViews, sheetFormatPr, cols,
     * sheetData, then sheetProtection — and Excel rejects the file outright if
     * it is wrong, so this method is the one place that order is expressed.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, true>  $boldRows
     * @param  array<string, int>  $strings  shared-string pool, filled in place
     */
    private function sheetXml(array $rows, array $boldRows, array &$strings): string
    {
        $body = '';
        $rowNumber = 0;
        $widest = 1;

        foreach ($rows as $row) {
            $rowNumber++;
            $widest = max($widest, count($row));
            $cells = '';
            $column = 0;

            foreach ($row as $value) {
                $reference = self::columnName($column).$rowNumber;
                $column++;

                if ($value === null || $value === '') {
                    continue; // an absent cell is how a spreadsheet spells "blank"
                }

                $cells .= $this->cellXml($reference, $value, isset($boldRows[$rowNumber - 1]), $strings);
            }

            $body .= '<row r="'.$rowNumber.'">'.$cells.'</row>';
        }

        $dimension = $rowNumber === 0
            ? 'A1'
            : 'A1:'.self::columnName($widest - 1).$rowNumber;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::XMLNS_MAIN.'">'
            .'<dimension ref="'.$dimension.'"/>'
            .'<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0"/></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<sheetData>'.$body.'</sheetData>'
            .'<sheetProtection sheet="1" objects="1" scenarios="1" formatCells="0" selectLockedCells="1"/>'
            .'</worksheet>';
    }

    /**
     * One cell, typed.
     *
     * The classification is deliberately narrow: an int, or a string that is
     * nothing but digits with at most one decimal point, is a number. Anything
     * else — an ISO date, a Jalali date, a code, a Persian label — is text and
     * goes into the shared-string pool. "2026-01-20" must stay text, so the
     * pattern is anchored and rejects it.
     *
     * @param  array<string, int>  $strings
     */
    private function cellXml(string $reference, mixed $value, bool $bold, array &$strings): string
    {
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        if (is_int($value)) {
            $style = $bold ? self::STYLE_BOLD : self::STYLE_INTEGER;

            return '<c r="'.$reference.'" s="'.$style.'" t="n"><v>'.$value.'</v></c>';
        }

        $text = (string) $value;

        if (preg_match('/^-?\d+$/', $text) === 1) {
            $style = $bold ? self::STYLE_BOLD : self::STYLE_INTEGER;

            return '<c r="'.$reference.'" s="'.$style.'" t="n"><v>'.$text.'</v></c>';
        }

        if (preg_match('/^-?\d+\.\d+$/', $text) === 1) {
            $style = $bold ? self::STYLE_BOLD : self::STYLE_DECIMAL;

            return '<c r="'.$reference.'" s="'.$style.'" t="n"><v>'.$text.'</v></c>';
        }

        $index = $this->intern($text, $strings);
        $style = $bold ? self::STYLE_BOLD : self::STYLE_DEFAULT;

        return '<c r="'.$reference.'" s="'.$style.'" t="s"><v>'.$index.'</v></c>';
    }

    /** @param array<string, int> $strings */
    private function intern(string $text, array &$strings): int
    {
        if (! array_key_exists($text, $strings)) {
            $strings[$text] = count($strings);
        }

        return $strings[$text];
    }

    // ── the other six parts ──────────────────────────────────────────────────

    private function contentTypesXml(): string
    {
        $spreadsheet = 'application/vnd.openxmlformats-officedocument.spreadsheetml';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="'.$spreadsheet.'.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="'.$spreadsheet.'.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="'.$spreadsheet.'.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="'.$spreadsheet.'.styles+xml"/>'
            .'</Types>';
    }

    private function packageRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::XMLNS_R.'/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::XMLNS_MAIN.'" xmlns:r="'.self::XMLNS_R.'">'
            .'<sheets><sheet name="'.$this->escape($this->safeSheetName($sheetName))
            .'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::XMLNS_R.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.self::XMLNS_R.'/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId3" Type="'.self::XMLNS_R.'/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>';
    }

    /** @param array<string, int> $strings */
    private function sharedStringsXml(array $strings): string
    {
        $items = '';

        foreach (array_keys($strings) as $text) {
            // xml:space="preserve" so a label that ends in a space survives.
            $items .= '<si><t xml:space="preserve">'.$this->escape((string) $text).'</t></si>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="'.self::XMLNS_MAIN.'" count="'.count($strings)
            .'" uniqueCount="'.count($strings).'">'.$items.'</sst>';
    }

    /**
     * Just enough of a style sheet to satisfy §15.10's formatting rules.
     *
     * «مبلغ به ریال، بدون جداکننده در سلول (فرمت سلول تنظیم شود)» — the grouping
     * is numFmt 164, applied to the cell, so the stored value stays 19521900000
     * and only the display carries separators. numFmt 165 gives weights their
     * three decimals («وزن به گرم با ۳ رقم اعشار») even when the trailing digits
     * are zeros.
     *
     * The fonts/fills/borders/cellStyleXfs blocks are the minimum Excel will
     * accept: it treats a missing fills entry or a fills count below two as a
     * corrupt file, whatever the styles actually reference.
     */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::XMLNS_MAIN.'">'
            .'<numFmts count="2">'
            .'<numFmt numFmtId="164" formatCode="#,##0"/>'
            .'<numFmt numFmtId="165" formatCode="0.000"/>'
            .'</numFmts>'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Tahoma"/></font>'
            .'<font><b/><sz val="11"/><name val="Tahoma"/></font>'
            .'</fonts>'
            .'<fills count="2">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    // ── packaging ────────────────────────────────────────────────────────────

    /**
     * ZipArchive only writes to a path, so the package is built in a temp file
     * and read back. The file is removed whatever happens, including on the
     * failure paths, because a report export is exactly the kind of thing that
     * runs thousands of times a day on a queue worker.
     *
     * @param  array<string, string>  $parts
     */
    private function zip(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the workbook');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the workbook archive for writing');
            }

            foreach ($parts as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            $zip->close();

            $binary = file_get_contents($path);

            if ($binary === false) {
                throw new RuntimeException('Could not read the workbook back');
            }

            return $binary;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function escape(string $value): string
    {
        // Control characters other than tab/newline/carriage return are illegal
        // in XML 1.0 and would make the whole workbook unopenable.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function safeSheetName(string $name): string
    {
        $clean = str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name);
        $clean = trim($clean);

        if ($clean === '') {
            $clean = 'Sheet1';
        }

        return mb_substr($clean, 0, self::MAX_SHEET_NAME);
    }
}
