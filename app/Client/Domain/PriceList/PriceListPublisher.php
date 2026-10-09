<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

use App\Client\Models\PriceListImport;
use App\Client\Models\PriceListImportRow;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Domain\Audit\Auditor;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemBrand;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\Unit;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a parsed import into the next version of the price list, in one
 * transaction: the items the file names are created or refreshed, the
 * version's prices written, the items the file does not name carried
 * forward from the list in force (or switched off, only when the file is a
 * full replacement), earlier versions superseded, and the item's cached
 * price refreshed. A tripped brake needs a typed acknowledgement first.
 */
final class PriceListPublisher
{
    public function publish(PriceListImport $import, User $approver, DateTimeInterface|string|null $effectiveFrom = null, ?string $acknowledgement = null, ?string $note = null): PriceListVersion
    {
        if ($import->status !== PriceListImport::PARSED) {
            throw new DomainException(__('Import :name is :status, not ready to publish.', ['name' => $import->original_filename, 'status' => $import->status]));
        }
        if ($import->brakeTripped() && blank($acknowledgement)) {
            throw new DomainException(__('Large changes need a second confirmation: :reasons', ['reasons' => implode(' ', (array) ($import->diff['brake_reasons'] ?? []))]));
        }
        $effectiveFrom = Carbon::parse($effectiveFrom ?? $import->effective_from ?? today())->toDateString();

        return DB::transaction(function () use ($import, $approver, $effectiveFrom, $acknowledgement, $note): PriceListVersion {
            $current = PriceListVersion::current();
            $version = PriceListVersion::query()->create([
                'effective_from' => $effectiveFrom,
                'status' => PriceListVersion::DRAFT,
                'source_file_path' => $import->stored_path,
                'note' => $note ?? $import->note,
            ]);

            $fromFile = 0;
            $units = $this->units();
            $named = [];
            $import->rows()->where('status', '!=', PriceListImportRow::BLOCKER)->orderBy('id')->chunkById(500, function ($rows) use ($version, $units, &$fromFile, &$named): void {
                foreach ($rows as $row) {
                    $item = $this->upsertItem($row, $units);
                    $row->forceFill(['item_id' => $item->id])->saveQuietly();
                    PriceListItem::query()->create(['version_id' => $version->id, 'item_id' => $item->id, 'price' => (int) $row->harga, 'qty_per_ctn' => $row->qty_per_ctn ?? 1, 'is_active' => (bool) $row->aktif]);
                    $item->forceFill(['sell_price' => (int) $row->harga])->saveQuietly(); // the cache of the list price
                    $named[$item->id] = true;
                    $fromFile++;
                }
            });

            $carried = 0;
            if ($current !== null) {
                foreach ($current->items()->orderBy('id')->get() as $old) {
                    if (isset($named[$old->item_id])) {
                        continue;
                    }
                    $active = $import->is_full_replacement ? false : (bool) $old->is_active;
                    PriceListItem::query()->create(['version_id' => $version->id, 'item_id' => $old->item_id, 'price' => (int) $old->price, 'qty_per_ctn' => $old->qty_per_ctn, 'is_active' => $active]);
                    if ($import->is_full_replacement && $old->is_active) {
                        Item::query()->whereKey($old->item_id)->update(['is_active' => false]);
                    }
                    $carried++;
                }
            }

            PriceListVersion::query()->where('status', PriceListVersion::PUBLISHED)->update(['status' => PriceListVersion::SUPERSEDED]);
            $version->forceFill(['status' => PriceListVersion::PUBLISHED, 'published_at' => now(), 'published_by' => $approver->id])->save();
            $import->forceFill(['status' => PriceListImport::PUBLISHED, 'version_id' => $version->id, 'approved_by' => $approver->id, 'approved_at' => now(), 'brake_acknowledgement' => $acknowledgement])->saveQuietly();

            Auditor::log('price_list_published', $version, "#{$version->id}", [
                'effective_from' => $effectiveFrom,
                'items_from_file' => $fromFile,
                'items_carried_forward' => $carried,
                'full_replacement' => (bool) $import->is_full_replacement,
                'brake' => $import->diff['brake_reasons'] ?? [],
                'acknowledgement' => $acknowledgement,
                'import_id' => $import->id,
            ]);

            return $version;
        });
    }

    public function discard(PriceListImport $import, User $actor): void
    {
        if (! in_array($import->status, [PriceListImport::UPLOADED, PriceListImport::PARSED, PriceListImport::FAILED], true)) {
            throw new DomainException(__('Import :name is :status and cannot be discarded.', ['name' => $import->original_filename, 'status' => $import->status]));
        }
        $import->forceFill(['status' => PriceListImport::DISCARDED])->saveQuietly();
        Auditor::log('price_list_import_discarded', $import, $import->original_filename, ['by' => $actor->id]);
    }

    /** The item a row names: created from the file, or refreshed in its descriptive fields; the active flag is the owner's. */
    private function upsertItem(PriceListImportRow $row, array $units): Item
    {
        $descriptive = array_filter([
            'name' => $row->description,
            'brand_id' => $row->merk ? $this->brand($row->merk)->id : null,
            'category_id' => $row->kategori ? $this->category($row->kategori)->id : null,
            'part_number' => $row->part_number,
            'vehicle' => $row->mobil,
            'product_type' => $row->tipe_produk,
            'notes' => $row->catatan,
        ], fn ($v) => $v !== null);

        $item = Item::query()->where('number', $row->kode)->first();
        if ($item === null) {
            $baseUnit = $units[strtoupper((string) ($row->satuan_dasar ?: 'PCS'))] ?? $units['PCS'];
            $item = Item::query()->create(['number' => $row->kode, 'name' => $row->description ?? $row->kode, 'unit1_id' => $baseUnit->id, 'is_active' => true, 'sell_price' => (int) $row->harga]
                + array_diff_key($descriptive, ['name' => 1]));
        } else {
            $item->forceFill($descriptive)->saveQuietly();
        }

        // The carton as a unit of the item, at the file's ratio; a changed ratio follows the file.
        if (($row->qty_per_ctn ?? 1) > 1 && isset($units['CTN'])) {
            $carton = $item->units()->where('unit_id', $units['CTN']->id)->first();
            if ($carton === null) {
                $item->units()->create(['sort' => (int) $item->units()->max('sort') + 1, 'unit_id' => $units['CTN']->id, 'ratio' => $row->qty_per_ctn, 'sell_price' => 0]);
            } elseif ((int) $carton->ratio !== (int) $row->qty_per_ctn) {
                $carton->forceFill(['ratio' => $row->qty_per_ctn])->saveQuietly();
            }
        }

        return $item;
    }

    /** @return array<string, Unit> PCS, SET and CTN by name, made when missing */
    private function units(): array
    {
        $units = [];
        foreach (['PCS' => 'UM.0018', 'SET' => 'UM.0021', 'CTN' => 'UM.0005'] as $name => $tax) {
            $units[$name] = Unit::query()->firstOrCreate(['name' => $name], ['unit_tax_code' => $tax]);
        }

        return $units;
    }

    private function brand(string $name): ItemBrand
    {
        return ItemBrand::query()->firstOrCreate(['name' => $name]);
    }

    private function category(string $name): ItemCategory
    {
        return ItemCategory::query()->whereNull('parent_id')->firstOrCreate(['name' => $name]);
    }
}
