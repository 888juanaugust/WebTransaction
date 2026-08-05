<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Tagihan;

use App\Filament\Portal\Concerns\ScopedToBuyer;
use App\Filament\Portal\Resources\Tagihan\Pages\ListTagihan;
use App\Filament\Portal\Resources\Tagihan\Pages\ViewTagihan;
use App\Filament\Portal\Resources\Tagihan\Schemas\TagihanDetail;
use App\Filament\Portal\Resources\Tagihan\Tables\TagihanTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Outstanding invoices with due dates — item 5 on the portal priority list, and
 * what ends "I didn't know it was due" conversations.
 *
 * Read-only by construction. What a customer owes comes from the invoice issued
 * against their order's price snapshots, and the payment ledger behind it is
 * append-only.
 */
class TagihanResource extends Resource
{
    use ScopedToBuyer;

    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Tagihan';

    protected static ?string $modelLabel = 'tagihan';

    protected static ?string $pluralModelLabel = 'tagihan';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'tagihan';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function table(Table $table): Table
    {
        return TagihanTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TagihanDetail::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTagihan::route('/'),
            'view' => ViewTagihan::route('/{record}'),
        ];
    }
}
