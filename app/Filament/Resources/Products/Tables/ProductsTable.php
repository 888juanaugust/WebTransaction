<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Domain\Money;
use App\Domain\Pricing\PriceResolver;
use App\Models\Company;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        $showPrices = auth()->user()?->role()->canSeePrices() ?? false;

        return $table
            ->columns(array_values(array_filter([
                TextColumn::make('kode')->label('KODE')->searchable()->sortable(),
                TextColumn::make('merk')->label('Merk')->searchable()->sortable(),
                TextColumn::make('kategori')->label('Kategori')->searchable()->toggleable(),
                TextColumn::make('tipe_produk')->label('Tipe')->searchable()->toggleable(),
                TextColumn::make('mobil')->label('Mobil')->searchable()->toggleable(),
                TextColumn::make('part_number')->label('Part number')->searchable()->toggleable(),

                TextColumn::make('satuan_dasar')->label('Satuan'),
                TextColumn::make('qty_per_ctn')->label('Isi/dus'),

                // The list price comes from resolvePrice() like everywhere
                // else — there is no second implementation of pricing here.
                $showPrices
                    ? TextColumn::make('harga_list')
                        ->label('Harga list')
                        ->state(fn (Product $record) => self::listPrice($record))
                    : null,

                IconColumn::make('aktif')->label('Aktif')->boolean(),
            ])))
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
                EditAction::make()->label('Ubah'),
            ])
            ->defaultSort('kode');
    }

    /**
     * List price for a SKU: resolved for a customer with no tier and no
     * overrides, which is exactly what "harga list" means.
     */
    private static function listPrice(Product $product): string
    {
        $resolution = app(PriceResolver::class)->resolve(
            new Company(['price_tier_id' => null]),
            $product->kode,
            1,
        );

        return $resolution->isPriced()
            ? Money::format($resolution->unitPrice)
            : '— belum ada harga —';
    }
}
