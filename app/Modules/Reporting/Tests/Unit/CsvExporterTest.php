<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Tests\Unit;

use App\Modules\Reporting\Application\Export\CsvExporter;
use App\Modules\Reporting\Application\Export\ExcelExporter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The export rules of §15.10, each one asserted on the bytes that come out.
 */
final class CsvExporterTest extends TestCase
{
    private CsvExporter $csv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csv = new CsvExporter;
    }

    #[Test]
    public function numbers_are_written_as_numbers_not_strings(): void
    {
        $output = $this->csv->export(
            'گزارش',
            ['شرح', 'مبلغ'],
            [['فروش طلا', 19_521_900_000]],
        );

        // «اعداد به‌صورت عدد ذخیره شوند، نه رشته (تا قابل جمع باشند)»
        self::assertStringContainsString(',19521900000', $output);

        // None of the things that would turn the cell into text.
        self::assertStringNotContainsString('"19521900000"', $output);
        self::assertStringNotContainsString('19,521,900,000', $output);
        self::assertStringNotContainsString("'19521900000", $output);
    }

    #[Test]
    public function weight_is_grams_with_exactly_three_decimals(): void
    {
        // «وزن به گرم با ۳ رقم اعشار»
        self::assertSame('1247.320', $this->csv->grams(1_247_320));
        self::assertSame('248.750', $this->csv->grams(248_750));
        self::assertSame('0.000', $this->csv->grams(0));
        self::assertSame('0.001', $this->csv->grams(1));
        self::assertSame('-0.500', $this->csv->grams(-500));
        self::assertSame('5.025', $this->csv->grams(5_025));

        // Trailing zeros survive: three decimals is the contract.
        self::assertSame('100.000', $this->csv->grams(100_000));
    }

    #[Test]
    public function a_gram_value_is_still_emitted_as_a_number(): void
    {
        $output = $this->csv->export(
            'گزارش',
            ['شرح', 'وزن'],
            [['موجودی', $this->csv->grams(1_247_320)]],
        );

        self::assertStringContainsString(',1247.320', $output);
        self::assertStringNotContainsString('"1247.320"', $output);
    }

    #[Test]
    public function purity_is_rendered_in_its_conventional_form(): void
    {
        self::assertSame('995.0', $this->csv->purity(9_950));
        self::assertSame('999.9', $this->csv->purity(9_999));
        self::assertSame('750.0', $this->csv->purity(7_500));
    }

    #[Test]
    public function a_jalali_column_sits_beside_a_sortable_gregorian_one(): void
    {
        // «تاریخ شمسی به‌صورت رشته + یک ستون تاریخ میلادی برای مرتب‌سازی»
        [$jalali, $gregorian] = $this->csv->dateColumns('2026-01-20');

        self::assertSame('1404/10/30', $jalali);
        self::assertSame('2026-01-20', $gregorian);

        // The Jalali column is text a member reads; only the Gregorian one is
        // in a form a spreadsheet recognises as a date, which is what makes
        // sorting, filtering by month and date arithmetic work on the export.
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2}$#', $jalali);
        self::assertMatchesRegularExpression('#^\d{4}-\d{2}-\d{2}$#', $gregorian);

        self::assertSame('2026', date('Y', (int) strtotime($gregorian)));

        // Read as a date, the Jalali string lands six centuries in the past —
        // it is a label, not a timestamp, and sorting a column of them by date
        // would be meaningless.
        self::assertSame('1404', date('Y', (int) strtotime($jalali)));
    }

    #[Test]
    public function the_file_ends_with_a_checksum_row(): void
    {
        // «ردیف آخر: جمع + checksum»
        $rows = [['الف', 1], ['ب', 2]];

        $output = $this->csv->export('گزارش', ['شرح', 'مبلغ'], $rows);
        $lines = array_values(array_filter(explode("\r\n", $output)));

        $last = end($lines);

        self::assertStringStartsWith('checksum,', (string) $last);
        self::assertMatchesRegularExpression('/^checksum,[0-9a-f]{64}$/', (string) $last);
    }

    #[Test]
    public function the_checksum_covers_the_data_and_only_the_data(): void
    {
        $rows = [['الف', 1], ['ب', 2]];

        $withOneHeader = $this->csv->checksum($rows);

        // Same rows, different metadata → same digest, so re-exporting the same
        // period is verifiably the same data.
        $a = $this->csv->export('گزارش', ['ش', 'م'], $rows, ['تاریخ تولید' => '2026-01-01']);
        $b = $this->csv->export('گزارش', ['ش', 'م'], $rows, ['تاریخ تولید' => '2026-06-01']);

        self::assertStringContainsString($withOneHeader, $a);
        self::assertStringContainsString($withOneHeader, $b);

        // Change a single value and the digest moves.
        self::assertNotSame($withOneHeader, $this->csv->checksum([['الف', 1], ['ب', 3]]));
    }

    #[Test]
    public function ambiguous_rows_cannot_collide_in_the_checksum(): void
    {
        self::assertNotSame(
            $this->csv->checksum([['a,b']]),
            $this->csv->checksum([['a', 'b']]),
        );
    }

    #[Test]
    public function text_containing_a_delimiter_is_quoted_but_numbers_never_are(): void
    {
        $output = $this->csv->export(
            'گزارش',
            ['شرح', 'مبلغ'],
            [['طلافروشی کریمی, شعبه بازار', 500]],
        );

        self::assertStringContainsString('"طلافروشی کریمی, شعبه بازار",500', $output);
    }

    #[Test]
    public function the_header_block_carries_the_report_metadata(): void
    {
        // «ردیف اول: عنوان گزارش، دوره، تاریخ تولید، نام سازمان»
        $output = $this->csv->export(
            'گردش طلا',
            ['شرح'],
            [['x']],
            ['سازمان' => 'طلافروشی کریمی', 'دوره' => '1404/08/01 تا 1404/08/30'],
        );

        self::assertStringStartsWith('گردش طلا', $output);
        self::assertStringContainsString('سازمان,طلافروشی کریمی', $output);
        self::assertStringContainsString('دوره,1404/08/01 تا 1404/08/30', $output);
    }

    #[Test]
    public function the_excel_variant_starts_with_a_byte_order_mark(): void
    {
        $excel = new ExcelExporter($this->csv);

        $output = $excel->export('گزارش', ['شرح'], [['فروش طلا', 500]]);

        // Without it Excel mangles Persian text.
        self::assertStringStartsWith("\xEF\xBB\xBF", $output);

        // And the numbers are still numbers underneath.
        self::assertStringContainsString(',500', $output);
    }

    #[Test]
    public function null_cells_are_empty_rather_than_the_word_null(): void
    {
        $output = $this->csv->export('گزارش', ['الف', 'ب'], [['x', null]]);

        self::assertStringContainsString('x,'."\r\n", $output);
        self::assertStringNotContainsString('null', $output);
    }
}
