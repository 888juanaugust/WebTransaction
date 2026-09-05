<?php

declare(strict_types=1);

namespace App\Filament\Resources\Regions;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\Regions\Pages\CreateRegion;
use App\Filament\Resources\Regions\Pages\EditRegion;
use App\Filament\Resources\Regions\Pages\ListRegions;
use App\Filament\Resources\Regions\Schemas\RegionForm;
use App\Filament\Resources\Regions\Tables\RegionsTable;
use App\Models\Region;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Wilayah — each one a complete, separate set of books.
 *
 * Owner only, like the staff screen and for the same reason: creating a
 * region creates a place data can live, and assigning people to one decides
 * what they can see. Both are questions of company structure, and company
 * structure is the admin's alone.
 *
 * No delete. A region that has ever traded is referenced by every document it
 * issued, and a region opened by mistake is deactivated — it stops appearing
 * in the switcher and stops accepting staff, and its rows stay readable.
 */
class RegionResource extends Resource
{
    protected static ?string $model = Region::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Wilayah';

    protected static ?string $modelLabel = 'wilayah';

    protected static ?string $pluralModelLabel = 'wilayah';

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENGATURAN;

    /** Just above Staf at 90 — you create the place, then put people in it. */
    protected static ?int $navigationSort = 89;

    protected static ?string $slug = 'wilayah';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canManageStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return RegionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegions::route('/'),
            'create' => CreateRegion::route('/create'),
            'edit' => EditRegion::route('/{record}/edit'),
        ];
    }
}
