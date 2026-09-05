<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierCreditNotes;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\SupplierCreditNotes\Pages\ListSupplierCreditNotes;
use App\Filament\Resources\SupplierCreditNotes\Tables\SupplierCreditNotesTable;
use App\Models\SupplierCreditNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Nota kredit pemasok — the supplier owes us less, and no goods moved.
 *
 * Sits beside Retur pembelian on purpose: they answer the same question and
 * choosing between them is the decision worth making. Goods went back → retur.
 * Only the price was wrong → this.
 */
class SupplierCreditNoteResource extends Resource
{
    protected static ?string $model = SupplierCreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PEMBELIAN;

    protected static ?string $navigationLabel = 'Nota kredit pemasok';

    protected static ?string $modelLabel = 'nota kredit pemasok';

    protected static ?string $pluralModelLabel = 'nota kredit pemasok';

    protected static ?int $navigationSort = 36;

    protected static ?string $slug = 'nota-kredit-pemasok';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canRecordPurchases() ?? false;
    }

    /** Drafted through the header action, which asks the right questions. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** A posted note has a journal entry. Drafts are discarded, not deleted. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Drafts nobody has posted.
     *
     * A note sitting in draft is a payable still overstated — the supplier has
     * agreed to the credit and the books have not heard about it.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $drafts = SupplierCreditNote::query()
            ->where('status', SupplierCreditNote::STATUS_DRAFT)
            ->count();

        return $drafts > 0 ? (string) $drafts : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return SupplierCreditNotesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierCreditNotes::route('/')];
    }
}
