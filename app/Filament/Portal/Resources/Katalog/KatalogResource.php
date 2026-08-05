<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Katalog;

use App\Filament\Portal\Concerns\ReadOnlyInPortal;
use App\Filament\Portal\Resources\Katalog\Pages\ListKatalog;
use App\Filament\Portal\Resources\Katalog\Tables\KatalogTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The catalogue with the buyer's own prices — item 3 on the portal priority
 * list.
 *
 * Products are not customer data, so this is deliberately *not* scoped to a
 * company: everyone sees the same catalogue. The prices are not the same,
 * which is the whole point of a B2B portal, and they come from resolvePrice()
 * for the signed-in buyer.
 *
 * Inactive SKUs are filtered out rather than shown greyed: a wholesale buyer
 * scanning a list wants the things they can actually order.
 */
class KatalogResource extends Resource
{
    use ReadOnlyInPortal;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Katalog';

    protected static ?string $modelLabel = 'produk';

    protected static ?string $pluralModelLabel = 'produk';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'katalog';

    protected static ?string $recordTitleAttribute = 'kode';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('aktif', true);
    }

    public static function table(Table $table): Table
    {
        return KatalogTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKatalog::route('/'),
        ];
    }
}
