<?php

declare(strict_types=1);

namespace App\Filament\Resources\Giros;

use App\Filament\Resources\Giros\Pages\ListGiros;
use App\Filament\Resources\Giros\Pages\ViewGiro;
use App\Filament\Resources\Giros\Schemas\GiroDetail;
use App\Filament\Resources\Giros\Tables\GirosTable;
use App\Models\Giro;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Bilyet giro, both directions, in one register.
 *
 * No create page and no edit page. A giro is a piece of paper somebody else
 * printed: its value, its date and its number are facts, not fields, and the
 * only thing that ever changes about one is what happened to it. Registering
 * one is a header action with three questions; everything after that is a
 * transition.
 *
 * A giro entered wrongly is cancelled with a reason, not corrected. That posts
 * the reversal the books need and leaves the mistake visible, which is the
 * same rule every other money document here follows.
 */
class GiroResource extends Resource
{
    protected static ?string $model = Giro::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Bilyet giro';

    protected static ?string $modelLabel = 'bilyet giro';

    protected static ?string $pluralModelLabel = 'bilyet giro';

    /*
     * Ungrouped, immediately after Faktur. A giro settles invoices and bills
     * alike, so it belongs to neither the sales side nor the purchasing side —
     * and a navigation group holding one item is a heading, not a group.
     */
    protected static ?int $navigationSort = 42;

    protected static ?string $slug = 'giro';

    protected static ?string $recordTitleAttribute = 'nomor_warkat';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canHandleGiro() ?? false;
    }

    /** Registered through the header actions, which ask the right questions. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Never. An outstanding giro has a journal entry behind it, and a settled
     * one is evidence. Wrong ones are cancelled.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Giro that can be banked today and have not been.
     *
     * The one number worth interrupting somebody for. A giro sitting in the
     * drawer past its date is money that could already be in the account, and
     * nothing else in the system is watching the date printed on it.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $due = Giro::query()
            ->open()
            ->masuk()
            ->whereNull('tanggal_setor')
            ->whereDate('tanggal_jatuh_tempo', '<=', now()->toDateString())
            ->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return GirosTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GiroDetail::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGiros::route('/'),
            'view' => ViewGiro::route('/{record}'),
        ];
    }
}
