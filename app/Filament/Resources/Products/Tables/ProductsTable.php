<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Domain\Money;
use App\Domain\Pricing\PriceResolver;
use App\Filament\Resources\Products\ProductResource;
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

        $showCost = auth()->user()?->role()->canSeeCost() ?? false;

        return $table
            // One query for every row's cost, not one per row.
            ->modifyQueryUsing(fn ($query) => $showCost ? $query->with('cost') : $query)
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
                        ->state(fn (Product $record, $livewire) => self::listPrice($record, $livewire))
                    : null,

                /*
                 * What we paid, on a tighter permission than what we sell for.
                 * Cost beside list price is margin, and margin belongs to
                 * finance and the owner — see Role::canSeeCost().
                 *
                 * Hidden by default even for them: this is the catalogue screen,
                 * and most of the time the question is "what do we sell this
                 * for", not "what did we pay".
                 */
                $showCost
                    ? TextColumn::make('harga_pokok')
                        ->label('HPP rata-rata')
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->state(fn (Product $record) => $record->cost === null || $record->cost->qty_base <= 0
                            ? '—'
                            : Money::format($record->cost->unitCost()))
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
                // Same reason as the create button: the edit route already
                // refuses anyone but the catalogue-keeper, so the link must
                // not be offered to the rest.
                EditAction::make()
                    ->label('Ubah')
                    ->visible(fn (Product $record) => ProductResource::canEdit($record)),
            ])
            ->defaultSort('kode');
    }

    /**
     * List price for a SKU: resolved for a customer with no tier and no
     * overrides, which is exactly what "harga list" means.
     *
     * The column is evaluated once per row, so the whole page is primed on the
     * first row rather than each row reading the price list for itself.
     */
    private static function listPrice(Product $product, $livewire): string
    {
        $resolver = app(PriceResolver::class);

        $resolver->prime(
            self::anonymousBuyer(),
            $livewire->getTableRecords()->pluck('kode')->all(),
        );

        $resolution = $resolver->resolve(self::anonymousBuyer(), $product->kode, 1);

        return $resolution->isPriced()
            ? Money::format($resolution->unitPrice)
            : '— belum ada harga —';
    }

    /** A customer with no tier and no overrides — the definition of list price. */
    private static function anonymousBuyer(): Company
    {
        return new Company(['price_tier_id' => null]);
    }
}
