<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierBills\Schemas;

use App\Domain\Money;
use App\Domain\Tax\TaxCalculator;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBillLine;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Entering a supplier's invoice.
 *
 * Lines point at goods receipt lines wherever possible, because that link is
 * what makes the three-way match possible at all — without it a bill is just an
 * amount somebody typed. Lines with no receipt behind them are allowed for the
 * things a supplier legitimately bills that never touched stock: freight,
 * handling, packaging.
 *
 * No tax is entered. DPP and PPN are computed per line when the bill is posted,
 * from the same TaxCalculator the sell side uses.
 */
class SupplierBillForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Faktur pemasok')
                    ->columns(3)
                    ->schema([
                        Select::make('supplier_id')
                            ->label('Pemasok')
                            ->options(fn () => Supplier::query()
                                ->where('aktif', true)->orderBy('nama')->pluck('nama', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($state, $set) => $set(
                                'due_date',
                                $state
                                    ? now()->addDays(
                                        (int) (Supplier::query()->find($state)?->payment_terms_days ?? 30)
                                    )->toDateString()
                                    : null
                            ))
                            ->helperText('Jatuh tempo mengikuti termin pemasok ini.'),

                        TextInput::make('nomor_faktur_supplier')
                            ->label('Nomor faktur pemasok')
                            ->required()
                            ->maxLength(60),

                        TextInput::make('nomor_faktur_pajak')
                            ->label('Nomor faktur pajak (NSFP)')
                            ->maxLength(30)
                            ->helperText('PPN hanya bisa dikreditkan bila nomor ini ada.'),

                        Select::make('purchase_order_id')
                            ->label('Pesanan pembelian')
                            ->options(fn (Get $get) => $get('supplier_id')
                                ? PurchaseOrder::query()
                                    ->where('supplier_id', $get('supplier_id'))
                                    ->orderByDesc('tanggal_po')
                                    ->pluck('nomor', 'id')
                                : [])
                            ->searchable()
                            ->placeholder('Tanpa PO'),

                        DatePicker::make('tanggal_faktur')
                            ->label('Tanggal faktur')
                            ->required()->default(now())
                            ->displayFormat('d/m/Y')->native(false),

                        DatePicker::make('due_date')
                            ->label('Jatuh tempo')
                            ->required()
                            ->displayFormat('d/m/Y')->native(false),
                    ]),

                Section::make('Baris tagihan')
                    ->description('Hubungkan ke penerimaan barang agar bisa dicocokkan tiga arah.')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->addActionLabel('Tambah baris')
                            ->reorderable(false)
                            ->columns(12)
                            ->minItems(1)
                            ->schema([
                                /*
                                 * Barang or biaya, and the difference is not
                                 * cosmetic: a goods line clears the accrual
                                 * against a receipt, and a cost line waits in
                                 * the clearing account to be spread over the
                                 * goods it belongs to. Getting it wrong parks
                                 * freight in Utang Belum Ditagih, where no
                                 * delivery is ever coming to clear it.
                                 */
                                Select::make('jenis')
                                    ->label('Jenis')
                                    ->options([
                                        SupplierBillLine::JENIS_BARANG => 'Barang',
                                        SupplierBillLine::JENIS_BIAYA => 'Biaya perolehan',
                                    ])
                                    ->default(SupplierBillLine::JENIS_BARANG)
                                    ->selectablePlaceholder(false)
                                    ->live()
                                    ->columnSpan(2)
                                    ->helperText(fn (Get $get) => $get('jenis') === SupplierBillLine::JENIS_BIAYA
                                        ? 'Ongkos angkut, bea masuk. Dibebankan ke barang lewat Biaya perolehan.'
                                        : null),

                                Select::make('goods_receipt_line_id')
                                    ->label('Penerimaan barang')
                                    ->options(fn (Get $get) => self::receiptLineOptions($get))
                                    ->searchable()
                                    ->live()
                                    ->placeholder('Tanpa penerimaan')
                                    // A cost line has no goods behind it by
                                    // definition, so the field is not offered.
                                    ->visible(fn (Get $get) => $get('jenis') !== SupplierBillLine::JENIS_BIAYA)
                                    ->columnSpan(3)
                                    ->afterStateUpdated(function ($state, $set) {
                                        $line = $state ? GoodsReceiptLine::query()->find($state) : null;

                                        if ($line === null) {
                                            return;
                                        }

                                        // Pre-fill from what actually arrived, so a
                                        // discrepancy is something somebody typed
                                        // deliberately rather than by accident.
                                        $set('sku', $line->sku);
                                        $set('qty_base', $line->qty_base);
                                        $set('line_total_rupiah', $line->line_value_rupiah);
                                    }),

                                TextInput::make('deskripsi')
                                    ->label('Keterangan')
                                    ->maxLength(120)
                                    ->columnSpan(3),

                                TextInput::make('qty_base')
                                    ->label('Jumlah (satuan dasar)')
                                    ->numeric()->minValue(0)->default(0)
                                    ->columnSpan(2),

                                TextInput::make('line_total_rupiah')
                                    ->label('Nilai (sebelum PPN)')
                                    ->numeric()->minValue(0)->required()
                                    ->prefix('Rp')
                                    ->live(onBlur: true)
                                    ->columnSpan(2)
                                    ->helperText(fn (Get $get) => self::taxPreview($get)),
                            ]),
                    ]),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ]);
    }

    /**
     * Receipt lines from this supplier that are worth billing against.
     *
     * Scoped to the chosen supplier: offering every receipt in the system would
     * make it trivial to bill one supplier's delivery to another.
     */
    private static function receiptLineOptions(Get $get): array
    {
        $supplierId = $get('../../supplier_id');

        if ($supplierId === null) {
            return [];
        }

        return GoodsReceiptLine::query()
            ->join('goods_receipts', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
            ->where('goods_receipts.supplier_id', $supplierId)
            ->where('goods_receipts.status', 'posted')
            ->orderByDesc('goods_receipts.tanggal_terima')
            ->limit(200)
            ->get(['goods_receipt_lines.*', 'goods_receipts.nomor AS receipt_nomor'])
            ->mapWithKeys(fn ($line) => [
                $line->id => sprintf(
                    '%s · %s · %s',
                    $line->receipt_nomor,
                    $line->sku,
                    Money::format((int) $line->line_value_rupiah),
                ),
            ])
            ->all();
    }

    /** What this line will add in PPN masukan once posted. */
    private static function taxPreview(Get $get): string
    {
        $value = (int) $get('line_total_rupiah');

        if ($value <= 0) {
            return ' ';
        }

        $breakdown = app(TaxCalculator::class)->forLine($value);

        return sprintf(
            'DPP %s · PPN masukan %s',
            Money::format($breakdown->dpp),
            Money::format($breakdown->ppn),
        );
    }
}
