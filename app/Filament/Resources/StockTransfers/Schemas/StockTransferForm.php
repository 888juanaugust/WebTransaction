<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockTransfers\Schemas;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Uom\Unit;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Moving stock between warehouses.
 *
 * The SKU list is what the source warehouse actually holds, with the free
 * quantity beside it — free meaning on hand less what is fenced off for
 * confirmed orders. Offering the whole catalogue would let somebody build a
 * transfer of goods that are not there, and find out at posting.
 *
 * No prices anywhere. This is a warehouse screen.
 */
class StockTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Transfer gudang')
                    ->columns(3)
                    ->schema([
                        Select::make('from_warehouse_id')
                            ->label('Dari gudang')
                            ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(fn ($set) => $set('lines', [])),

                        Select::make('to_warehouse_id')
                            ->label('Ke gudang')
                            ->options(fn (Get $get) => Warehouse::query()
                                ->where('aktif', true)
                                ->when($get('from_warehouse_id'), fn ($q, $id) => $q->whereKeyNot($id))
                                ->pluck('nama', 'id'))
                            ->required()
                            ->helperText('Gudang asal tidak muncul di sini — transfer butuh dua gudang berbeda.'),

                        DatePicker::make('tanggal')
                            ->label('Tanggal')
                            ->default(now())
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required(),

                        Textarea::make('catatan')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('mis. penataan ulang stok menjelang lebaran'),

                        Hidden::make('nomor')
                            ->default(fn () => app(DocumentNumberGenerator::class)->nextStockTransferNumber()),
                        Hidden::make('created_by')->default(fn () => auth()->id()),
                    ]),

                Section::make('Baris')
                    ->description('Jumlah dalam satuan dasar. Yang sudah dipesan pelanggan tidak bisa dipindah.')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->addActionLabel('Tambah baris')
                            ->defaultItems(0)
                            ->columns(3)
                            ->schema([
                                Select::make('sku')
                                    ->label('Barang')
                                    ->options(fn (Get $get) => static::stockedSkus($get('../../from_warehouse_id')))
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->columnSpan(2),

                                TextInput::make('qty_base')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->maxValue(fn (Get $get) => static::available(
                                        $get('../../from_warehouse_id'), $get('sku')
                                    ) ?: null)
                                    ->helperText(function (Get $get) {
                                        $free = static::available($get('../../from_warehouse_id'), $get('sku'));

                                        return $free === null
                                            ? null
                                            : 'Tersedia '.number_format($free, 0, ',', '.').' unit dasar.';
                                    }),

                                Hidden::make('ordered_unit')->default(Unit::Pcs->value),
                                Hidden::make('qty_per_ctn_snapshot')->default(1),
                            ]),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    private static function stockedSkus(mixed $warehouseId): array
    {
        if (! $warehouseId) {
            return [];
        }

        $levels = StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('qty_on_hand', '>', 0)
            ->orderBy('sku')
            ->get();

        $names = Product::query()
            ->whereIn('kode', $levels->pluck('sku'))
            ->pluck('description', 'kode');

        return $levels->mapWithKeys(fn ($level) => [
            $level->sku => trim($level->sku.' — '.($names[$level->sku] ?? '')),
        ])->all();
    }

    /** On hand less what is fenced off for confirmed orders. */
    private static function available(mixed $warehouseId, mixed $sku): ?int
    {
        if (! $warehouseId || ! $sku) {
            return null;
        }

        $level = StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('sku', $sku)
            ->first();

        return $level === null ? null : max(0, (int) $level->qty_on_hand - (int) $level->qty_reserved);
    }
}
