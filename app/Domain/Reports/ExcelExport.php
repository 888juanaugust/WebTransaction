<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Every report leaves as a spreadsheet: a title row, the headers, the rows as shown. Text is written as text, never
 * as a formula, so a name or memo typed as "=HYPERLINK(...)" cannot run when the file is opened.
 */
final class ExcelExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function download(string $title, string $period, array $headers, iterable $rows): BinaryFileResponse
    {
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Stored under a name nobody else's export can take; downloaded under the report's.
        $file = $dir.'/'.Str::uuid()->toString().'.xlsx';
        $name = preg_replace('/[^\w-]+/', '-', strtolower($title)).'-'.now()->format('Ymd-His').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($file);
        $writer->addRow(self::row([$title]));
        $writer->addRow(self::row([$period]));
        $writer->addRow(self::row([]));
        $writer->addRow(self::row($headers));
        foreach ($rows as $row) {
            $writer->addRow(self::row(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, array_values($row))));
        }
        $writer->close();

        return response()->download($file, $name)->deleteFileAfterSend(true);
    }

    /** @param  list<scalar|null>  $values */
    private static function row(array $values): Row
    {
        return new Row(array_map(fn ($v) => is_string($v) && $v !== '' ? new StringCell($v, null) : Cell::fromValue($v), $values));
    }
}
