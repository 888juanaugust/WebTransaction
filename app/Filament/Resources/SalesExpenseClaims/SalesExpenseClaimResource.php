<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesExpenseClaims;

use App\Domain\Access\Role;
use App\Filament\Resources\SalesExpenseClaims\Pages\ListSalesExpenseClaims;
use App\Filament\Resources\SalesExpenseClaims\Tables\SalesExpenseClaimsTable;
use App\Models\SalesExpenseClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The register of expedition-expense claims — filed by sales, verified by
 * finance, created and moved only through SalesExpenseClaims.
 *
 * No create page: the claim form is a header action on the list, because
 * three fields do not need a page. No edit either — a filed claim is a
 * statement, and a wrong one is rejected and re-filed, not quietly amended.
 */
class SalesExpenseClaimResource extends Resource
{
    protected static ?string $model = SalesExpenseClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Biaya ekspedisi';

    protected static ?string $modelLabel = 'biaya ekspedisi';

    protected static ?string $pluralModelLabel = 'biaya ekspedisi';

    protected static ?int $navigationSort = 46;

    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role();

        return in_array($role, [Role::Sales, Role::Finance, Role::Owner], true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    /** A sales reads their own claims; finance and the Owner read them all. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['sales', 'decider', 'expense']);
        $user = auth()->user();

        return $user?->role() === Role::Sales
            ? $query->where('sales_user_id', $user->getKey())
            : $query;
    }

    public static function table(Table $table): Table
    {
        return SalesExpenseClaimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesExpenseClaims::route('/'),
        ];
    }
}
