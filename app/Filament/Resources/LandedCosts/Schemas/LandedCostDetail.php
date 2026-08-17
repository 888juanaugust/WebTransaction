<?php

declare(strict_types=1);

namespace App\Filament\Resources\LandedCosts\Schemas;

use App\Domain\Money;
use App\Models\LandedCost;
use App\Models\LandedCostLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One allocation, with the arithmetic on display.
 *
 * The point of this screen is that somebody can check the split before posting
 * it, and explain it afterwards. So every intermediate figure is shown — the
 * weight each line carried, its share of the charge, and how that share
 * divided — rather than only the totals. An allocation whose numbers cannot be
 * followed is one nobody will trust enough to sign.
 */
class LandedCostDetail
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Biaya')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('nomor')->label('Nomor'),

                        TextEntry::make('supplierBillLine.supplierBill.supplier.nama')
                            ->label('Ditagih oleh')
                            ->placeholder('—'),

                        TextEntry::make('amount_rupiah')
                            ->label('Nilai biaya')
                            ->state(fn (LandedCost $record) => Money::format((int) $record->amount_rupiah)),

                        TextEntry::make('tanggal')->label('Tanggal')->date('d/m/Y'),

                        TextEntry::make('dasar')
                            ->label('Dasar pembagian')
                            ->state(fn (LandedCost $record) => $record->dasar->label())
                            ->helperText(fn (LandedCost $record) => $record->dasar->description())
                            ->columnSpan(2),

                        TextEntry::make('supplierBillLine.supplierBill.nomor')
                            ->label('Dari tagihan')
                            ->placeholder('—'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => $state === LandedCost::STATUS_POSTED
                                ? 'Diposting'
                                : 'Draf')
                            ->color(fn (string $state) => $state === LandedCost::STATUS_POSTED
                                ? 'success'
                                : 'gray'),

                        TextEntry::make('catatan')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Pembagian')
                    ->description(
                        'Bagian barang yang masih ada menaikkan nilai persediaan. Bagian yang sudah '
                        .'terjual masuk ke HPP bulan ini, karena harga pokok pengiriman yang sudah '
                        .'lewat tidak dihitung ulang.'
                    )
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->columns(6)
                            ->schema([
                                TextEntry::make('sku')->label('Kode'),

                                TextEntry::make('goodsReceiptLine.goodsReceipt.nomor')
                                    ->label('Penerimaan')
                                    ->placeholder('—'),

                                TextEntry::make('dasar_nilai')
                                    ->label('Bobot')
                                    ->state(fn (LandedCostLine $record) => $record->landedCost->dasar->value === 'nilai'
                                        ? Money::format((int) $record->dasar_nilai)
                                        : number_format((int) $record->dasar_nilai, 0, ',', '.')),

                                TextEntry::make('amount_rupiah')
                                    ->label('Bagian biaya')
                                    ->state(fn (LandedCostLine $record) => Money::format((int) $record->amount_rupiah)),

                                TextEntry::make('ke_persediaan_rupiah')
                                    ->label('Ke persediaan')
                                    ->state(fn (LandedCostLine $record) => $record->landedCost->isDraft()
                                        ? '—'
                                        : Money::format((int) $record->ke_persediaan_rupiah))
                                    // The quantity that decided it, so the
                                    // split can be checked rather than trusted.
                                    ->helperText(fn (LandedCostLine $record) => $record->landedCost->isDraft()
                                        ? null
                                        : self::stillOnShelf($record)),

                                TextEntry::make('ke_hpp_rupiah')
                                    ->label('Ke HPP')
                                    ->state(fn (LandedCostLine $record) => $record->landedCost->isDraft()
                                        ? '—'
                                        : Money::format((int) $record->ke_hpp_rupiah)),
                            ]),
                    ]),

                Section::make('Hasil')
                    ->columns(3)
                    // Nothing to show on a draft: the split is not decided
                    // until posting, because it depends on what is on the
                    // shelf at that moment.
                    ->visible(fn (LandedCost $record) => $record->isPosted())
                    ->schema([
                        TextEntry::make('ke_persediaan_rupiah')
                            ->label('Masuk ke nilai persediaan')
                            ->state(fn (LandedCost $record) => Money::format((int) $record->ke_persediaan_rupiah)),

                        TextEntry::make('ke_hpp_rupiah')
                            ->label('Dibebankan ke HPP')
                            ->state(fn (LandedCost $record) => Money::format((int) $record->ke_hpp_rupiah)),

                        TextEntry::make('postedBy.name')->label('Diposting oleh')->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * "192 dari 192 unit YH-1001 masih ada" — the ratio that decided the split.
     *
     * Both figures are **per SKU**, not per line, and the wording has to say so
     * because the two scopes are easy to confuse and the mistake is silent. The
     * stored `qty_on_hand` is the group's, so printing it beside this row's own
     * `qty_base` produced things like "192 dari 72": a part that appeared on two
     * receipts showed the whole shelf against one delivery's quantity, which
     * reads as a system that cannot count.
     *
     * The split genuinely is decided per SKU — one moving average for the
     * company cannot tell one carton from another — so the honest fix is to
     * name the scope rather than invent a per-line figure to sit beside it.
     */
    private static function stillOnShelf(LandedCostLine $line): string
    {
        $received = $line->landedCost->lines
            ->where('sku', $line->sku)
            ->sum('qty_base');

        return sprintf(
            '%s dari %s unit %s masih ada',
            number_format((int) $line->qty_on_hand, 0, ',', '.'),
            number_format((int) $received, 0, ',', '.'),
            $line->sku,
        );
    }
}
