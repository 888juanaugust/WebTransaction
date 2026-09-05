<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Invoices are generated from confirmed orders and their amounts come from the
 * line snapshots. Nothing here edits a total — the hard rule is that whoever
 * confirms a payment must not be able to move what is owed.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::KEUANGAN;

    protected static ?string $navigationLabel = 'Faktur';

    protected static ?string $modelLabel = 'faktur';

    protected static ?string $pluralModelLabel = 'faktur';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'nomor';

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

    /** A faktur is withdrawn with a credit note, never taken off the record. */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }
}
