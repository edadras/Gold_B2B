<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Tests\Unit;

use App\Modules\Reporting\Application\Export\CsvExporter;
use App\Modules\Reporting\Application\Export\ExcelExporter;
use App\Modules\Reporting\Application\Export\XlsxWriter;
use App\Modules\Reporting\Domain\ReportFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use ZipArchive;

/**
 * The .xlsx export of §15.10, asserted by opening the file the way Excel does.
 *
 * Everything here reads the produced package with ZipArchive rather than
 * grepping the bytes: a workbook is a ZIP, so a string assertion over the
 * compressed stream proves nothing at all, and the two requirements that
 * justified writing a workbook in the first place — the RTL view and the sheet
 * lock — live inside one of the parts.
 */
final class XlsxExporterTest extends TestCase
{
    private ExcelExporter $excel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->excel = new ExcelExporter(new CsvExporter);
    }

    #[Test]
    public function the_package_contains_every_part_a_workbook_needs(): void
    {
        $zip = $this->open($this->sample());

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
            'xl/sharedStrings.xml',
            'xl/styles.xml',
        ] as $part) {
            self::assertNotFalse($zip->locateName($part), "Missing part: {$part}");
        }

        $zip->close();
    }

    #[Test]
    public function every_part_is_well_formed_xml(): void
    {
        $zip = $this->open($this->sample());

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $xml = (string) $zip->getFromName($name);

            self::assertInstanceOf(
                SimpleXMLElement::class,
                simplexml_load_string($xml),
                "Malformed XML in {$name}",
            );
        }

        $zip->close();
    }

    #[Test]
    public function the_sheet_is_right_to_left_and_locked(): void
    {
        $sheet = $this->sheetXml($this->sample());

        // «راست‌به‌چپ بودن شیت» — column A on the right, as a Persian report reads.
        self::assertStringContainsString('rightToLeft="1"', $sheet);

        // «قفل شیت برای جلوگیری از ویرایش تصادفی»
        self::assertStringContainsString('<sheetProtection', $sheet);
        self::assertStringContainsString('sheet="1"', $sheet);

        // And the schema's element order, which Excel enforces absolutely.
        self::assertLessThan(
            strpos($sheet, '<sheetData>'),
            strpos($sheet, '<sheetViews>'),
        );
        self::assertGreaterThan(
            strpos($sheet, '</sheetData>'),
            strpos($sheet, '<sheetProtection'),
        );
    }

    #[Test]
    public function a_numeric_cell_is_typed_as_a_number_and_not_as_a_string(): void
    {
        $sheet = $this->sheetXml($this->sample());

        // «اعداد به‌صورت عدد ذخیره شوند، نه رشته (تا قابل جمع باشند)»
        self::assertMatchesRegularExpression(
            '/<c r="[A-Z]+\d+" s="\d+" t="n"><v>19521900000<\/v><\/c>/',
            $sheet,
        );

        // The two ways it could have been written as text, neither of which a
        // member could sum:
        self::assertStringNotContainsString('<is>', $sheet, 'An inline string was written');
        self::assertStringNotContainsString('>19521900000</t>', $sheet, 'The number was interned as a shared string');

        // And no separators in the stored value — they belong to the format.
        self::assertStringNotContainsString('19,521,900,000', $sheet);
    }

    #[Test]
    public function a_weight_keeps_three_decimals_as_a_number(): void
    {
        $sheet = $this->sheetXml($this->sample());

        // «وزن به گرم با ۳ رقم اعشار» — still t="n", so it can be summed, and
        // still 248.750 rather than 248.75, which is a different figure to a
        // careless reader.
        self::assertMatchesRegularExpression(
            '/<c r="[A-Z]+\d+" s="'.XlsxWriter::STYLE_DECIMAL.'" t="n"><v>248\.750<\/v><\/c>/',
            $sheet,
        );
    }

    #[Test]
    public function dates_stay_text_in_both_columns(): void
    {
        $strings = $this->partXml($this->sample(), 'xl/sharedStrings.xml');
        $sheet = $this->sheetXml($this->sample());

        // The Jalali column is what a member reads; the ISO column is what a
        // spreadsheet can sort by. Neither may be mistaken for a number — an
        // ISO date typed as a number would sort as arithmetic.
        self::assertStringContainsString('1404/10/30', $strings);
        self::assertStringContainsString('2026-01-20', $strings);
        self::assertStringNotContainsString('<v>2026-01-20</v>', $sheet);
    }

    #[Test]
    public function the_last_rows_are_the_totals_and_the_checksum(): void
    {
        $csv = new CsvExporter;
        $rows = $this->rows();

        $strings = $this->partXml($this->sample(), 'xl/sharedStrings.xml');
        $sheet = $this->sheetXml($this->sample());

        // «ردیف آخر: جمع + checksum»
        self::assertStringContainsString('جمع', $strings);
        self::assertStringContainsString('checksum', $strings);
        self::assertStringContainsString($csv->checksum($rows), $strings);

        // Two trades of 248.750 g and 100.000 g total 348.750, summed with
        // bcmath rather than floats.
        self::assertStringContainsString('<v>348.750</v>', $sheet);

        // 19,521,900,000 + 7,848,000,000 = 27,369,900,000, stored bare.
        self::assertStringContainsString('<v>27369900000</v>', $sheet);
    }

    #[Test]
    public function the_title_and_metadata_block_open_the_sheet(): void
    {
        $strings = $this->partXml($this->sample(), 'xl/sharedStrings.xml');

        // «ردیف اول: عنوان گزارش، دوره، تاریخ تولید، نام سازمان»
        self::assertStringContainsString('گزارش معاملات', $strings);
        self::assertStringContainsString('طلافروشی کریمی', $strings);
        self::assertStringContainsString('1404/08/01 تا 1404/08/30', $strings);
    }

    #[Test]
    public function the_workbook_names_its_sheet_after_the_report(): void
    {
        $workbook = $this->partXml($this->sample(), 'xl/workbook.xml');

        self::assertStringContainsString('name="گزارش معاملات"', $workbook);
    }

    #[Test]
    public function an_empty_report_still_produces_a_valid_workbook(): void
    {
        $zip = $this->open($this->excel->export('گزارش خالی', ['شرح'], []));

        self::assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');

        self::assertInstanceOf(SimpleXMLElement::class, simplexml_load_string($sheet));
        self::assertStringContainsString('rightToLeft="1"', $sheet);

        $zip->close();
    }

    #[Test]
    public function the_format_enum_advertises_the_workbook(): void
    {
        self::assertSame('xlsx', ReportFormat::EXCEL->extension());
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ReportFormat::EXCEL->mimeType(),
        );
        self::assertTrue(ReportFormat::EXCEL->isBinary());

        // A byte-order mark in front of a ZIP would simply corrupt it.
        self::assertFalse(ReportFormat::EXCEL->needsByteOrderMark());

        // The CSV export is still an option, and still text.
        self::assertSame('csv', ReportFormat::CSV->extension());
        self::assertFalse(ReportFormat::CSV->isBinary());
    }

    #[Test]
    public function column_names_run_past_z(): void
    {
        self::assertSame('A', XlsxWriter::columnName(0));
        self::assertSame('Z', XlsxWriter::columnName(25));
        self::assertSame('AA', XlsxWriter::columnName(26));
        self::assertSame('AB', XlsxWriter::columnName(27));
        self::assertSame('BA', XlsxWriter::columnName(52));
    }

    // ── fixtures and plumbing ────────────────────────────────────────────────

    /** @return array<int, array<int, mixed>> */
    private function rows(): array
    {
        $csv = new CsvExporter;

        return [
            [88231, ...$csv->dateColumns('2026-01-20'), $csv->grams(248_750), 19_521_900_000],
            [88232, ...$csv->dateColumns('2026-01-21'), $csv->grams(100_000), 7_848_000_000],
        ];
    }

    private function sample(): string
    {
        return $this->excel->export(
            'گزارش معاملات',
            ['شناسه', 'تاریخ شمسی', 'تاریخ میلادی', 'وزن (گرم)', 'مبلغ (ریال)'],
            $this->rows(),
            ['سازمان' => 'طلافروشی کریمی', 'دوره' => '1404/08/01 تا 1404/08/30'],
        );
    }

    private function sheetXml(string $workbook): string
    {
        return $this->partXml($workbook, 'xl/worksheets/sheet1.xml');
    }

    private function partXml(string $workbook, string $part): string
    {
        $zip = $this->open($workbook);
        $xml = $zip->getFromName($part);
        $zip->close();

        self::assertIsString($xml, "Missing part: {$part}");

        return $xml;
    }

    /** Opens the in-memory package the only way ZipArchive can: through a file. */
    private function open(string $workbook): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');

        self::assertIsString($path);

        file_put_contents($path, $workbook);

        $zip = new ZipArchive;

        self::assertTrue($zip->open($path) === true, 'The export is not a readable ZIP');

        // Safe to unlink now: the handle keeps the inode alive until close().
        unlink($path);

        return $zip;
    }
}
