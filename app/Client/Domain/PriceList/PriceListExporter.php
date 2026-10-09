<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The list in force as a workbook in the canonical columns: what staff edit and upload again. Text is written as text, never a formula. */
final class PriceListExporter
{
    /** @return iterable<list<scalar|null>> the header, then one row per item of the version, by item number */
    public function rows(PriceListVersion $version): iterable
    {
        yield CanonicalColumns::COLUMNS;
        $query = PriceListItem::query()->where('version_id', $version->id)
            ->with(['item.brand', 'item.category', 'item.unit1'])
            ->join('items', 'items.id', '=', 'price_list_items.item_id')
            ->orderBy('items.number')
            ->select('price_list_items.*');
        foreach ($query->cursor() as $row) {
            $item = $row->item;
            yield [
                $item->number,
                $item->brand?->name ?? '',
                $item->category?->name ?? '',
                $item->product_type ?? '',
                $item->vehicle ?? '',
                $item->part_number ?? '',
                $item->name,
                (int) $row->qty_per_ctn,
                $item->unit1?->name ?? 'PCS',
                (int) $row->price,
                $row->is_active ? 'Y' : 'N',
                $item->notes ?? '',
            ];
        }
    }

    public function write(PriceListVersion $version, string $file): void
    {
        $writer = new Writer;
        $writer->openToFile($file);
        foreach ($this->rows($version) as $row) {
            $writer->addRow(new Row(array_map(fn ($v) => is_string($v) && $v !== '' ? new StringCell($v, null) : Cell::fromValue($v), $row)));
        }
        $writer->close();
    }

    public function download(PriceListVersion $version): BinaryFileResponse
    {
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/'.Str::uuid()->toString().'.xlsx';
        $this->write($version, $file);

        return response()->download($file, "harga-v{$version->id}-".now()->format('Ymd').'.xlsx')->deleteFileAfterSend(true);
    }
}
