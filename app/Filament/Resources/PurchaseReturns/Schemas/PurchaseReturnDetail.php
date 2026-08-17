<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseReturns\Schemas;

use App\Domain\Money;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One return, with the split on display.
 *
 * The figure people come here for is not the total — it is **which part of it
 * reduced a debt and which part cancelled an accrual**, because that is what
 * decides whether the supplier owes us money or simply owes us less. So the
 * two are separate rows rather than a single "nilai retur", and each line
 * shows the quantity behind its own share.
 *
 * The variance is shown too, and shown as its own figure rather than folded
 * into the total. It is the difference between what the goods were carried at
 * when they left and what the supplier is giving back, which under
 * moving-average costing is a real gain or loss and not a rounding error —
 * hiding it would make the arithmetic look wrong to anybody who checked it.
 */
class PurchaseReturnDetail
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Retur')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('nomor')->label('Nomor'),

                        TextEntry::make('supplier.nama')->label('Pemasok')->placeholder('—'),

                        TextEntry::make('goodsReceipt.nomor')
                            ->label('Dari penerimaan')
                            ->placeholder('—'),

                        TextEntry::make('tanggal')->label('Tanggal')->date('d/m/Y'),

                        TextEntry::make('warehouse.nama')->label('Keluar dari gudang')->placeholder('—'),

                        // No warning colour on the empty case, for the reason
                        // set out on the table: a colour closure never runs
                        // against a placeholder.
                        TextEntry::make('nomor_nota_kredit_supplier')
                            ->label('Nota kredit pemasok')
                            ->placeholder('Belum diterima'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => $state === PurchaseReturn::STATUS_POSTED
                                ? 'Diposting'
                                : 'Draf')
                            ->color(fn (string $state) => $state === PurchaseReturn::STATUS_POSTED
                                ? 'success'
                                : 'gray'),

                        TextEntry::make('postedBy.name')->label('Diposting oleh')->placeholder('—'),

                        TextEntry::make('alasan')->label('Alasan')->columnSpanFull(),
                    ]),

                Section::make('Barang')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->columns(5)
                            ->schema([
                                TextEntry::make('sku')->label('Kode'),

                                TextEntry::make('deskripsi')->label('Barang')->placeholder('—'),

                                TextEntry::make('qty_base')
                                    ->label('Jumlah')
                                    ->state(fn (PurchaseReturnLine $record) => number_format(
                                        (int) $record->qty_base, 0, ',', '.'
                                    )),

                                TextEntry::make('nilai_ditagih_rupiah')
                                    ->label('Mengurangi utang')
                                    ->state(fn (PurchaseReturnLine $record) => static::draftOr(
                                        $record,
                                        fn () => Money::format((int) $record->nilai_ditagih_rupiah),
                                    ))
                                    ->helperText(fn (PurchaseReturnLine $record) => $record->purchaseReturn->isPosted()
                                        && $record->qty_ditagih > 0
                                            ? sprintf(
                                                '%s unit, ditagih di %s',
                                                number_format((int) $record->qty_ditagih, 0, ',', '.'),
                                                $record->supplierBill?->nomor ?? '—',
                                            )
                                            : null),

                                TextEntry::make('nilai_belum_ditagih_rupiah')
                                    ->label('Membatalkan akrual')
                                    ->state(fn (PurchaseReturnLine $record) => static::draftOr(
                                        $record,
                                        fn () => Money::format((int) $record->nilai_belum_ditagih_rupiah),
                                    ))
                                    ->helperText(fn (PurchaseReturnLine $record) => $record->purchaseReturn->isPosted()
                                        && $record->qtyBelumDitagih() > 0
                                            ? sprintf(
                                                '%s unit, belum pernah ditagih',
                                                number_format($record->qtyBelumDitagih(), 0, ',', '.'),
                                            )
                                            : null),
                            ]),
                    ]),

                Section::make('Hasil')
                    ->columns(4)
                    // Nothing to show on a draft: every figure is decided at
                    // posting, and stock leaves at the average at that instant.
                    ->visible(fn (PurchaseReturn $record) => $record->isPosted())
                    ->schema([
                        TextEntry::make('total_rupiah')
                            ->label('Dikreditkan pemasok')
                            ->state(fn (PurchaseReturn $record) => Money::format((int) $record->total_rupiah))
                            ->helperText(fn (PurchaseReturn $record) => sprintf(
                                '%s + PPN %s',
                                Money::format((int) $record->nilai_ditagih_rupiah),
                                Money::format((int) $record->ppn_rupiah),
                            )),

                        TextEntry::make('nilai_belum_ditagih_rupiah')
                            ->label('Akrual dibatalkan')
                            ->state(fn (PurchaseReturn $record) => Money::format(
                                (int) $record->nilai_belum_ditagih_rupiah
                            )),

                        TextEntry::make('nilai_persediaan_rupiah')
                            ->label('Keluar dari persediaan')
                            ->state(fn (PurchaseReturn $record) => Money::format(
                                (int) $record->nilai_persediaan_rupiah
                            )),

                        TextEntry::make('selisih_rupiah')
                            ->label('Selisih harga')
                            ->state(fn (PurchaseReturn $record) => Money::format((int) $record->selisih_rupiah))
                            ->color(fn (PurchaseReturn $record) => $record->selisih_rupiah != 0 ? 'warning' : null)
                            ->helperText(fn (PurchaseReturn $record) => $record->selisih_rupiah == 0
                                ? 'Nilai persediaan sama dengan yang dikreditkan.'
                                : 'Barang dicatat dengan harga rata-rata yang sudah bergerak sejak '
                                    .'diterima. Selisihnya masuk ke Selisih Harga Pembelian.'),
                    ]),
            ]);
    }

    /** @param  callable(): string  $posted */
    private static function draftOr(PurchaseReturnLine $line, callable $posted): string
    {
        return $line->purchaseReturn->isPosted() ? $posted() : '—';
    }
}
