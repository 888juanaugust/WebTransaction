<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\PostingLogs;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Shared\Format;
use App\Filament\Resources\GeneralLedger\PostingLogs\Pages\ListPostingLogs;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpResource;
use App\Models\GeneralLedger\Posting;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use UnitEnum;

/** Journal Activity Log: every posting ever written, active or superseded, by whom and when. Read-only. */
class PostingLogResource extends ErpResource
{
    protected static ?string $model = Posting::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'Posting';

    protected static ?string $recordTitleAttribute = 'posting_key';

    public static function menuKey(): MenuKey
    {
        return MenuKey::JournalActivityLog;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof UnitEnum ? ($action->value ?? $action->name) : $action;

        return in_array($ability, ['viewAny', 'view'], true) && app(HakAkses::class)->allows(auth()->user(), static::menuKey(), Hak::View)
            ? Response::allow()
            : Response::deny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['journalEntry', 'postedBy', 'supersededBy']))
            ->columns([
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('journalEntry.number')->label(__('Number'))->fontFamily('mono')->placeholder('—'),
                TextColumn::make('journalEntry.source_number')->label(__('Trans. No.'))->fontFamily('mono')->placeholder('—'),
                TextColumn::make('document_type')->label(__('Transaction type'))->badge()->color('gray')->formatStateUsing(fn (string $state) => Format::documentType($state)),
                TextColumn::make('revision')->label(__('Rev.'))->alignEnd(),
                TextColumn::make('posted_at')->label(__('Posted'))->formatStateUsing(fn ($state) => Format::dateTime($state))->sortable(),
                TextColumn::make('postedBy.name')->label(__('By'))->placeholder(__('System')),
                TextColumn::make('superseded_at')->label(__('Superseded'))->formatStateUsing(fn ($state) => Format::dateTime($state))->placeholder(__('active'))
                    ->badge()->color(fn ($state) => $state ? 'gray' : 'success'),
                TextColumn::make('supersededBy.name')->label(__('By'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('document_type')->label(__('Transaction type'))
                    ->options(fn () => collect(array_keys(Relation::morphMap()))->mapWithKeys(fn (string $k) => [$k => Format::documentType($k)])->sort()->all()),
                TernaryFilter::make('active')->label(__('Active'))->placeholder(__('All'))->trueLabel('Active only')->falseLabel('Superseded only')
                    ->queries(
                        true: fn ($query) => $query->whereNull('superseded_at'),
                        false: fn ($query) => $query->whereNotNull('superseded_at'),
                    ),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPostingLogs::route('/')];
    }
}
