<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoodsReceipts\Schemas;

use App\Domain\Money;
use App\Domain\Uom\Unit;
use App\Models\Product;
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
 * Entering a goods receipt from the supplier's paperwork.
 *
 * Only ever edits a draft. Once posted, the movements are in the ledger and the
 * average cost has moved — editing behind that is exactly what the append-only
 * rule exists to prevent, so the resource refuses to open a posted receipt.
 *
 * Cost is typed per *received* unit, because that is the figure printed on the
 * supplier's invoice and the one a person can check against it. The per-base
 * cost is derived; nobody types a division.
 */
class GoodsReceiptForm
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
                                ->where('aktif', true)
                                ->orderBy('nama')
                                ->pluck('nama', 'id'))
                            ->searchable()
                            ->required(),

                        Select::make('warehouse_id')
                            ->label('Gudang penerima')
                            ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                            ->required()
                            ->default(fn () => Warehouse::query()->where('aktif', true)->value('id')),

                        DatePicker::make('tanggal_terima')
                            ->label('Tanggal terima')
                            ->required()
                            ->default(now())
                            ->displayFormat('d/m/Y')
                            // The native browser picker ignores displayFormat and
                            // renders in the browser's locale, so this showed
                            // 08/13/2026 to a user who reads d/m/Y. On an
                            // ambiguous date — 05/06 — that is a wrong date
                            // nobody notices until the stock take.
                            ->native(false),

                        TextInput::make('nomor_surat_jalan_supplier')
                            ->label('Nomor surat jalan pemasok')
                            ->maxLength(60),

                        TextInput::make('nomor_faktur_supplier')
                            ->label('Nomor faktur pemasok')
                            ->maxLength(60)
                            ->helperText('Dipakai saat mencocokkan penerimaan dengan tagihan pemasok.'),
                    ]),

                Section::make('Barang diterima')
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
                                        ->where('aktif', true)
                                        ->orderBy('kode')
                                        ->get()
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
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(3),

                                TextInput::make('unit_cost_rupiah')
                                    ->label('Harga beli / satuan')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->prefix('Rp')
                                    ->live(onBlur: true)
                                    ->columnSpan(3)
                                    // The two numbers a person checks against
                                    // the invoice: what this line comes to, and
                                    // what that works out to per piece.
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
     * Fill in what the ledger and the valuation need, derived rather than typed.
     *
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

        // Value is quantity × the invoiced unit cost, computed once here in
        // whole rupiah. Nothing downstream re-derives it.
        $data['line_value_rupiah'] = $qty * $unitCost;

        return $data;
    }

    private static function linePreview(Get $get): string
    {
        $sku = $get('sku');
        $qty = (int) $get('ordered_qty');
        $unitCost = (int) $get('unit_cost_rupiah');

        if ($sku === null || $qty <= 0 || $unitCost <= 0) {
            return ' ';
        }

        $product = Product::query()->find($sku);

        if ($product === null) {
            return ' ';
        }

        $unit = Unit::from($get('ordered_unit') ?? Unit::Pcs->value);
        $qtyBase = $unit->toBaseQtyForProduct($qty, $product);
        $value = $qty * $unitCost;

        $perBase = $qtyBase > 0 ? intdiv($value * 2 + $qtyBase, $qtyBase * 2) : 0;

        return sprintf(
            '%s · %d %s @ %s',
            Money::format($value),
            $qtyBase,
            $product->satuan_dasar ?? 'PCS',
            Money::format($perBase),
        );
    }
}
