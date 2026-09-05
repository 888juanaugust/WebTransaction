<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\RelationManagers\CustomerUsersRelationManager;
use App\Filament\Resources\Companies\Schemas\CompanyForm;
use App\Filament\Resources\Companies\Tables\CompaniesTable;
use App\Models\Company;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENJUALAN;

    protected static ?string $navigationLabel = 'Pelanggan';

    protected static ?string $modelLabel = 'pelanggan';

    protected static ?string $pluralModelLabel = 'pelanggan';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'nama';

    /** Warehouse staff must not see credit or customer financial data. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    /** What the inherited default was already doing, now on purpose. */
    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    /**
     * The Owner, and only for a customer nothing has happened to.
     *
     * EditCompany renders a Delete button and this method did not exist, so
     * Filament answered yes for everybody who could open the page — measured,
     * **a sales rep deleted a customer outright**. What goes with the row is
     * the approval, the credit limit somebody decided on, the NPWP and the
     * seats the Owner assigned through TeamAssigner.
     *
     * A customer with orders or invoices is held by foreign keys and the
     * database refuses on its own; that is exactly the case where the row is
     * evidence. What was left unguarded was the other case — an account
     * approved this morning, deletable by the person who is paid on its
     * sales, with the credit decision going quietly with it. The everyday
     * answer to "we stopped selling to them" is the status field.
     */
    public static function canDelete($record): bool
    {
        return auth()->user()?->role()->canManageStaff() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // Portal logins for this customer. There is no self-registration —
            // a wholesale account exists only after staff verify the business.
            CustomerUsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
