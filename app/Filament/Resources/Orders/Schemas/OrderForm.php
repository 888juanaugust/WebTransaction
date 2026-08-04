<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Domain\Money;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Stock\StockLedger;
use App\Domain\Uom\Unit;
use App\Models\Company;
use App\Models\Product;
use App\Models\Warehouse;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Order entry.
 *
 * Only ever edits a draft. Once an order is confirmed its lines carry price
 * snapshots and a stock reservation, and editing those behind the state
 * machine's back is precisely what the invariants exist to prevent.
 *
 * Prices shown here are a live preview from resolvePrice() — an indication, not
 * a commitment. The binding number is the snapshot taken at `confirmed`, which
 * is why nothing on this form writes to the price columns.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Stack the sections. Side by side, the line editor gets half the
            // width and the product names wrap onto four lines each — this is
            // the screen sales spend the day in, so it gets the full page.
            ->columns(1)
            ->components([
                Section::make('Pelanggan')
                    ->columns(3)
                    ->schema([
                        Select::make('company_id')
                            ->label('Pelanggan')
                            ->options(fn () => Company::query()
                                ->where('status', Company::STATUS_ACTIVE)
                                ->orderBy('nama')
                                ->pluck('nama', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            // The customer decides the price, so changing it after
                            // lines exist would silently invalidate every preview.
                            ->disabledOn('edit')
                            ->helperText('Hanya pelanggan berstatus aktif yang bisa dipesankan.'),

                        Select::make('warehouse_id')
                            ->label('Gudang')
                            ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                            ->required()
                            ->live()
                            ->default(fn () => Warehouse::query()->where('aktif', true)->value('id')),

                        TextInput::make('po_pelanggan')
                            ->label('Nomor PO pelanggan')
                            ->maxLength(60)
                            ->helperText('Opsional. Dipakai pelanggan untuk mencocokkan tagihan.'),
                    ]),

                Section::make('Baris order')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            // Without this the raw relationship name ("Lines")
                            // shows above the rows.
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
                                    ->columnSpan(5),

                                Select::make('ordered_unit')
                                    ->label('Satuan')
                                    ->options([
                                        Unit::Pcs->value => 'PCS',
                                        Unit::Set->value => 'SET',
                                        Unit::Ctn->value => 'DUS',
                                    ])
                                    ->default(Unit::Pcs->value)
                                    ->required()
                                    ->live()
                                    ->columnSpan(2),

                                /*
                                 * The estimate hangs off the quantity field rather
                                 * than sitting in its own column.
                                 *
                                 * helperText takes a closure and re-evaluates on
                                 * every render, which is what a live preview needs.
                                 * A disabled TextInput keeps whatever it was
                                 * hydrated with and never updates; Placeholder is
                                 * deprecated in v4; and a TextEntry did not render
                                 * inside a form repeater at all. This does, and it
                                 * puts the number under the box being typed into.
                                 */
                                TextInput::make('ordered_qty')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(5)
                                    ->helperText(fn (Get $get) => self::linePreview($get)),
                            ])
                            // qty_base is derived, never typed: the ledger counts
                            // base units and a hand-entered conversion is a
                            // rounding error waiting to happen.
                            ->mutateRelationshipDataBeforeCreateUsing(
                                fn (array $data) => self::withDerivedQuantities($data)
                            )
                            ->mutateRelationshipDataBeforeSaveUsing(
                                fn (array $data) => self::withDerivedQuantities($data)
                            ),
                    ]),

                Textarea::make('catatan')->label('Catatan')->columnSpanFull(),
            ]);
    }

    /**
     * Fill in the snapshot columns the ledger and the state machine rely on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withDerivedQuantities(array $data): array
    {
        $product = Product::query()->find($data['sku'] ?? null);

        if ($product === null) {
            return $data;
        }

        $unit = Unit::from($data['ordered_unit'] ?? Unit::Pcs->value);
        $qty = (int) ($data['ordered_qty'] ?? 0);

        $data['qty_per_ctn_snapshot'] = $product->qty_per_ctn;
        $data['satuan_dasar_snapshot'] = $product->satuan_dasar;
        $data['qty_base'] = $unit->toBaseQtyForProduct($qty, $product);

        return $data;
    }

    /**
     * One line of feedback under the quantity box: what this line is likely to
     * cost, and whether the warehouse can actually supply it.
     *
     * "Likely": the binding number is the snapshot taken at `confirmed`.
     */
    private static function linePreview(Get $get): ?string
    {
        $price = self::pricePreview($get);

        if ($price === '—') {
            return null;
        }

        $stock = self::stockNote($get);

        return $stock === null ? $price : "{$price} · {$stock}";
    }

    /** Indicative line total, resolved for this customer at today's date. */
    private static function pricePreview(Get $get): string
    {
        $sku = $get('sku');
        $companyId = $get('../../company_id');
        $qty = (int) ($get('ordered_qty') ?? 0);

        if (blank($sku) || blank($companyId) || $qty < 1) {
            return '—';
        }

        $product = Product::query()->find($sku);
        $company = Company::query()->find($companyId);

        if ($product === null || $company === null) {
            return '—';
        }

        try {
            $unit = Unit::from($get('ordered_unit') ?? Unit::Pcs->value);
            $qtyBase = $unit->toBaseQtyForProduct($qty, $product);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $resolution = app(PriceResolver::class)->resolve($company, $sku, $qtyBase);

        if (! $resolution->isPriced()) {
            return 'Belum ada harga';
        }

        return Money::format($resolution->unitPrice * $qtyBase)." ({$qtyBase} {$product->satuan_dasar})";
    }

    /** Warn about stock before submitting, not at approval time. */
    private static function stockNote(Get $get): ?string
    {
        $sku = $get('sku');
        $warehouseId = $get('../../warehouse_id');
        $qty = (int) ($get('ordered_qty') ?? 0);

        if (blank($sku) || blank($warehouseId) || $qty < 1) {
            return null;
        }

        $product = Product::query()->find($sku);

        if ($product === null) {
            return null;
        }

        try {
            $qtyBase = Unit::from($get('ordered_unit') ?? Unit::Pcs->value)
                ->toBaseQtyForProduct($qty, $product);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $available = app(StockLedger::class)->available($sku, (int) $warehouseId);

        return $available >= $qtyBase
            ? "Stok tersedia: {$available}"
            : "Stok kurang: tersedia {$available}, butuh {$qtyBase}";
    }
}
