<?php

declare(strict_types=1);

namespace App\Filament\Resources\StoreVisits;

use App\Domain\Access\Role;
use App\Filament\Resources\StoreVisits\Pages\CreateStoreVisit;
use App\Filament\Resources\StoreVisits\Pages\ListStoreVisits;
use App\Models\StoreVisit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kunjungan toko — the visit log.
 *
 * A sales records their own visits (photo, coordinates, the moment);
 * marketing reads their customers'; finance and the Owner read everything
 * and pull the monthly archive before the 2-month photo purge. No edit
 * and no delete for anybody: a visit is evidence, and evidence that can
 * be tidied is evidence of nothing.
 */
class StoreVisitResource extends Resource
{
    protected static ?string $model = StoreVisit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Kunjungan toko';

    protected static ?string $modelLabel = 'kunjungan';

    protected static ?string $pluralModelLabel = 'kunjungan';

    protected static ?int $navigationSort = 14;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role(), [Role::Sales, Role::Marketing, Role::Finance, Role::Owner], true);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role() === Role::Sales;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['sales', 'company']);
        $user = auth()->user();

        return match ($user?->role()) {
            Role::Sales => $query->where('sales_user_id', $user->getKey()),
            Role::Marketing => $query->whereHas('company', fn ($c) => $c->where('marketing_user_id', $user->getKey())),
            default => $query,
        };
    }

    public static function table(Table $table): Table
    {
        return Tables\StoreVisitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoreVisits::route('/'),
            'create' => CreateStoreVisit::route('/baru'),
        ];
    }
}
