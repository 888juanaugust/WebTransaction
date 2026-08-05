<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Katalog\Tables;

use App\Domain\Money;
use App\Domain\Pricing\PriceResolver;
use App\Filament\Portal\Actions\TambahKeKeranjangAction;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class KatalogTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('kode')
            ->emptyStateHeading('Katalog belum tersedia')
            ->emptyStateDescription('Daftar harga sedang disiapkan. Hubungi tim kami untuk sementara.')
            ->columns([
                TextColumn::make('kode')->label('KODE')->searchable()->sortable(),
                TextColumn::make('merk')->label('Merk')->searchable()->sortable(),
                TextColumn::make('description')->label('Nama barang')->searchable()->wrap(),
                TextColumn::make('kategori')->label('Kategori')->searchable()->toggleable(),
                TextColumn::make('mobil')->label('Mobil')->searchable()->toggleable(),
                TextColumn::make('part_number')->label('Part number')->searchable()->toggleable(),

                TextColumn::make('satuan_dasar')->label('Satuan'),
                TextColumn::make('qty_per_ctn')->label('Isi/dus'),

                TextColumn::make('harga')
                    ->label('Harga Anda')
                    ->state(fn (Product $record, $livewire) => self::price($record, $livewire))
                    // "per PCS" rather than "per satuan dasar": shorter, so it
                    // fits the last column, and it names the actual unit.
                    ->description(fn (Product $record) => 'per '.$record->satuan_dasar),
            ])
            ->filters([
                SelectFilter::make('merk')
                    ->label('Merk')
                    ->options(array_combine(
                        config('pricelist.known_brands'),
                        config('pricelist.known_brands'),
                    )),

                SelectFilter::make('kategori')
                    ->label('Kategori')
                    ->options(array_combine(
                        config('pricelist.known_categories'),
                        config('pricelist.known_categories'),
                    )),
            ])
            ->recordActions([
                TambahKeKeranjangAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * This buyer's price for one SKU, from the one pricing function.
     *
     * Resolved at quantity 1 — a catalogue price is the "from" figure, and any
     * quantity break shows up on the order once the quantity is known.
     *
     * The column is evaluated per row, so the whole page is primed on the first
     * one rather than each row reading the price list for itself.
     */
    private static function price(Product $product, $livewire): string
    {
        $company = self::company();

        if ($company === null) {
            return '—';
        }

        $resolver = app(PriceResolver::class);
        $resolver->prime($company, $livewire->getTableRecords()->pluck('kode')->all());

        $resolution = $resolver->resolve($company, $product->kode, 1);

        return $resolution->isPriced()
            ? Money::format($resolution->unitPrice)
            // Never "Rp 0". A SKU with no published price is one to ask about,
            // not one to assume is free.
            : 'Hubungi kami';
    }

    private static function company(): ?Company
    {
        $user = auth('customer')->user();

        return $user instanceof CustomerUser ? $user->company : null;
    }
}
