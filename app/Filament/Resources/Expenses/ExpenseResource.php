<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Beban — money out on something other than goods.
 *
 * Rent, wages, electricity, fuel, the courier. Everything the business spends
 * that is not paying a supplier for stock, which until now had no way into the
 * books at all.
 *
 * No create page and no edit page, for the same reason as every other money
 * document here: recording one is a single action that asks five questions and
 * posts immediately, and a wrong one is reversed rather than corrected. An
 * edit form would let somebody change an amount that a journal entry has
 * already reported.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static \UnitEnum|string|null $navigationGroup = 'Buku besar';

    protected static ?string $navigationLabel = 'Beban';

    protected static ?string $modelLabel = 'beban';

    protected static ?string $pluralModelLabel = 'beban';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'beban';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canPostJournals() ?? false;
    }

    /** Recorded through the header action, which asks the right questions. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** Never. It has a journal entry behind it. Wrong ones are reversed. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
        ];
    }
}
