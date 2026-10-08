<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\AuditLogs;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Format;
use App\Filament\Resources\Company\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpResource;
use App\Models\Company\AuditLog;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use UnitEnum;

/** The Activity Log: read-only, the standard's columns and filters. */
class AuditLogResource extends ErpResource
{
    protected static ?string $model = AuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function menuKey(): MenuKey
    {
        return MenuKey::ActivityLog;
    }

    /** Nothing here is ever created, edited or deleted from the screen. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof UnitEnum ? ($action->value ?? $action->name) : $action;

        return in_array($ability, ['viewAny', 'view'], true) && app(HakAkses::class)->allows(auth()->user(), static::menuKey(), Hak::View)
            ? Response::allow()
            : Response::deny();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label(__('Timestamp'))->formatStateUsing(fn ($state) => Format::dateTime($state)),
            TextEntry::make('user.name')->label(__('User'))->placeholder(__('System')),
            TextEntry::make('action')->label(__('Action'))->badge()->formatStateUsing(fn (string $state) => self::actionLabel($state)),
            TextEntry::make('document_type')->label(__('Transaction type'))->formatStateUsing(fn (?string $state) => self::typeLabel($state))->placeholder('—'),
            TextEntry::make('reference')->label(__('Reference'))->placeholder('—'),
            TextEntry::make('trans_date')->label(__('Transaction date'))->formatStateUsing(fn ($state) => Format::date($state))->placeholder('—'),
            TextEntry::make('ip')->label(__('IP address'))->placeholder('—'),
            TextEntry::make('meta')
                ->label(__('Details'))
                ->state(fn (AuditLog $record) => $record->meta ? json_encode($record->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null)
                ->fontFamily(FontFamily::Mono)
                ->size('xs')
                ->placeholder('—')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                Tanggal::make('trans_date')->label(__('Transaction date'))->placeholder('—'),
                TextColumn::make('reference')->label(__('Reference'))->searchable()->limit(40)->placeholder('—')
                    ->formatStateUsing(fn (?string $state, AuditLog $record) => self::reference($state, $record)),
                TextColumn::make('action')->label(__('Action'))->badge()->formatStateUsing(fn (string $state) => self::actionLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        'created', 'posted' => 'success',
                        'deleted', 'unposted' => 'danger',
                        'updated', 'preference_changed' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('document_type')->label(__('Transaction type'))->formatStateUsing(fn (?string $state) => self::typeLabel($state))->placeholder('—'),
                TextColumn::make('created_at')->label(__('Timestamp'))->formatStateUsing(fn ($state) => Format::dateTime($state))->sortable(),
                TextColumn::make('user.name')->label(__('User'))->placeholder(__('System')),
                TextColumn::make('user.email')->label(__('Email'))->placeholder('—'),
                TextColumn::make('ip')->label(__('IP address'))->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('trans_date')
                    ->schema([
                        DatePicker::make('trans_from')->label(__('Transaction date from'))->native(false),
                        DatePicker::make('trans_until')->label(__('until'))->native(false),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['trans_from'] ?? null, fn ($query, $d) => $query->whereDate('trans_date', '>=', $d))
                        ->when($data['trans_until'] ?? null, fn ($query, $d) => $query->whereDate('trans_date', '<=', $d))),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('Logged from'))->native(false),
                        DatePicker::make('until')->label(__('until'))->native(false),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($query, $d) => $query->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($query, $d) => $query->whereDate('created_at', '<=', $d))),
                SelectFilter::make('document_type')->label(__('Transaction type'))
                    ->options(fn () => collect(array_keys(Relation::morphMap()))->mapWithKeys(fn (string $k) => [$k => self::typeLabel($k)])->sort()->all()),
                SelectFilter::make('user_id')->label(__('User'))->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('action')->label(__('Action'))
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action')->mapWithKeys(fn (string $a) => [$a => self::actionLabel($a)])->all()),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->persistFiltersInSession()
            ->recordActions([ViewAction::make()->slideOver()])
            ->paginated([25, 50, 100]);
    }

    public static function actionLabel(string $action): string
    {
        return Format::code($action, 'audit');
    }

    /** A preference change names its preference in the reader's language, whatever the writer's was. */
    public static function reference(?string $state, AuditLog $record): ?string
    {
        $key = $record->action === 'preference_changed' ? PreferensiKey::tryFrom((string) ($record->meta['key'] ?? '')) : null;

        return $key?->label() ?? $state;
    }

    public static function typeLabel(?string $type): string
    {
        return Format::documentType($type);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
