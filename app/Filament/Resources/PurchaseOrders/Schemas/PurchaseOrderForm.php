<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Domain\Money;
use App\Domain\Uom\Unit;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\Supplier;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Writing a purchase order.
 *
 * Only ever edits a draft — once sent, the document records what was agreed
 * with the supplier, and receiving against a line somebody edited afterwards
 * would compare deliveries to a moving target.
 *
 * Cost is entered per *ordered* unit, matching how a supplier quotes. The last
 * price paid is shown beside the box, because "what did we pay last time" is
 * the question a buyer actually asks before agreeing a price.
 */
class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Dokumen')
                    ->columns(3)
                    ->schema([
                        Select::make('supplier_id')
                            ->label('Pemasok')
                            ->options(fn () => Supplier::query()
                                ->where('aktif', true)->orderBy('nama')->pluck('nama', 'id'))
                            ->searchable()
                            ->required(),

                        Select::make('warehouse_id')
                            ->label('Gudang tujuan')
                            ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                            ->required()
                            ->default(fn () => Warehouse::query()->where('aktif', true)->value('id')),

                        DatePicker::make('tanggal_po')
                            ->label('Tanggal PO')
                            ->required()
                            ->default(now())
                            ->displayFormat('d/m/Y')
                            ->native(false),

                        DatePicker::make('tanggal_diharapkan')
                            ->label('Diharapkan tiba')
                            ->displayFormat('d/m/Y')
                            ->native(false),

                        TextInput::make('referensi_supplier')
                            ->label('Referensi pemasok')
                            ->maxLength(60)
                            ->helperText('Nomor yang dipakai pemasok untuk pesanan ini, bila ada.'),
                    ]),

                Section::make('Barang dipesan')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->addActionLabel('Tambah baris')
                            ->reorderable(false)
                            ->columns(12)
                            ->minItems(1)
                            ->schema([
                                Select::make('sku')
                                    ->label('Produk')
                                    ->options(fn () => Product::query()
                                        ->where('aktif', true)->orderBy('kode')->get()
                                        ->mapWithKeys(fn (Product $p) => [
                                            $p->kode => "{$p->kode} — {$p->merk} {$p->description}",
                                        ]))
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->columnSpan(4),

                                Select::make('ordered_unit')
                                    ->label('Satuan')
                                    ->options([
                                        Unit::Pcs->value => 'PCS',
                                        Unit::Set->value => 'SET',
                                        Unit::Ctn->value => 'DUS',
                                    ])
                                    ->default(Unit::Ctn->value)
                                    ->required()
                                    ->live()
                                    ->columnSpan(2),

                                TextInput::make('ordered_qty')
                                    ->label('Jumlah')
                                    ->numeric()->minValue(1)->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(3),

                                TextInput::make('unit_cost_rupiah')
                                    ->label('Harga beli / satuan')
                                    ->numeric()->minValue(0)->required()
                                    ->prefix('Rp')
                                    ->live(onBlur: true)
                                    ->columnSpan(3)
                                    ->helperText(fn (Get $get) => self::linePreview($get)),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(
                                fn (array $data) => self::withDerivedValues($data)
                            )
                            ->mutateRelationshipDataBeforeSaveUsing(
                                fn (array $data) => self::withDerivedValues($data)
                            ),
                    ]),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withDerivedValues(array $data): array
    {
        $product = Product::query()->find($data['sku'] ?? null);

        if ($product === null) {
            return $data;
        }

        $unit = Unit::from($data['ordered_unit'] ?? Unit::Pcs->value);
        $qty = (int) ($data['ordered_qty'] ?? 0);
        $unitCost = (int) ($data['unit_cost_rupiah'] ?? 0);

        $data['qty_per_ctn_snapshot'] = $product->qty_per_ctn;
        $data['satuan_dasar_snapshot'] = $product->satuan_dasar;
        $data['qty_base'] = $unit->toBaseQtyForProduct($qty, $product);
        $data['line_value_rupiah'] = $qty * $unitCost;

        return $data;
    }

    /** The line total, and what we paid for this SKU last time. */
    private static function linePreview(Get $get): string
    {
        $sku = $get('sku');

        if ($sku === null) {
            return ' ';
        }

        $product = Product::query()->find($sku);

        if ($product === null) {
            return ' ';
        }

        $qty = (int) $get('ordered_qty');
        $unitCost = (int) $get('unit_cost_rupiah');
        $unit = Unit::from($get('ordered_unit') ?? Unit::Pcs->value);

        $parts = [];

        if ($qty > 0 && $unitCost > 0) {
            $qtyBase = $unit->toBaseQtyForProduct($qty, $product);
            $value = $qty * $unitCost;
            $perBase = $qtyBase > 0 ? intdiv($value * 2 + $qtyBase, $qtyBase * 2) : 0;

            $parts[] = sprintf(
                '%s · %d %s @ %s',
                Money::format($value), $qtyBase, $product->satuan_dasar ?? 'PCS', Money::format($perBase),
            );
        }

        $last = ProductCost::query()->where('sku', $sku)->value('last_cost_rupiah');

        if ($last !== null) {
            $parts[] = 'terakhir dibeli '.Money::format((int) $last).'/'.($product->satuan_dasar ?? 'PCS');
        }

        return $parts === [] ? ' ' : implode(' · ', $parts);
    }
}
