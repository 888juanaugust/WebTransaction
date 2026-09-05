<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteForm;
use App\Filament\Resources\CreditNotes\Tables\CreditNotesTable;
use App\Models\CreditNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Nota kredit — money going back to a customer.
 *
 * Visible to anyone who can see what customers owe, but creatable only by
 * whoever can set prices. That split is the control: a credit note reduces the
 * amount owed, which is editing the invoice amount by another name, and
 * CLAUDE.md's hard rule is that whoever confirms a payment must not be able to
 * do that. So Finance read these and cannot raise one — they are chasing the
 * debt, and they must not be able to make it disappear.
 */
class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::KEUANGAN;

    protected static ?string $navigationLabel = 'Nota kredit';

    protected static ?string $modelLabel = 'nota kredit';

    protected static ?string $pluralModelLabel = 'nota kredit';

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'nota-kredit';

    protected static ?string $recordTitleAttribute = 'nomor';

    /**
     * Anyone who may see what a customer owes may see why it went down —
     * and Inventori, who see no credit data elsewhere, see this screen
     * because verifying returs is their key to turn.
     */
    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role();

        return ($role?->canSeeCreditData() ?? false) || ($role?->canVerifyReturns() ?? false);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role()->canIssueCreditNote() ?? false;
    }

    /**
     * A posted note is never edited — by anybody, including the owner.
     *
     * The same rule the invoice and the supplier bill carry. A credit note
     * that can be edited after posting is an invoice amount that can be edited
     * after issue, one indirection removed.
     */
    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->role()->canIssueCreditNote() ?? false) && $record->isDraft();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function form(Schema $schema): Schema
    {
        return CreditNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CreditNotesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
            'create' => CreateCreditNote::route('/create'),
            'edit' => EditCreditNote::route('/{record}/edit'),
        ];
    }
}
