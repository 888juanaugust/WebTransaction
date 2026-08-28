<?php

declare(strict_types=1);

namespace App\Filament\Resources\DebtRemovals;

use App\Domain\Access\Role;
use App\Filament\Resources\DebtRemovals\Pages\ListDebtRemovals;
use App\Filament\Resources\DebtRemovals\Tables\DebtRemovalsTable;
use App\Models\DebtRemoval;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The register of debt-removal claims — filed by marketing, decided by
 * finance, created and moved only through DebtRemover.
 *
 * No create page and no edit page: a claim is filed from the invoice it
 * belongs to, and after that the only things anyone may do to it are the
 * two decisions.
 */
class DebtRemovalResource extends Resource
{
    protected static ?string $model = DebtRemoval::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?string $navigationLabel = 'Penghapusan piutang';

    protected static ?string $modelLabel = 'penghapusan piutang';

    protected static ?string $pluralModelLabel = 'penghapusan piutang';

    protected static ?int $navigationSort = 45;

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    /**
     * A team member sees their own customers' claims; finance and the Owner
     * see the region's whole register. A claim you can neither decide nor
     * re-file is only noise.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['invoice', 'company', 'initiator', 'decider']);
        $user = auth()->user();

        return match ($user?->role()) {
            Role::Marketing => $query->whereHas('company', fn ($q) => $q->where('marketing_user_id', $user->getKey())),
            Role::Sales => $query->whereHas('company', fn ($q) => $q->where('sales_user_id', $user->getKey())),
            default => $query,
        };
    }

    public static function table(Table $table): Table
    {
        return DebtRemovalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDebtRemovals::route('/'),
        ];
    }
}
