<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\RecurringTransactions;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Company\RecurringRunner;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Company\RecurringTransactions\Pages\CreateRecurringTransaction;
use App\Filament\Resources\Company\RecurringTransactions\Pages\EditRecurringTransaction;
use App\Filament\Resources\Company\RecurringTransactions\Pages\ListRecurringTransactions;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PricedDocumentForm;
use App\Models\Company\RecurringTransaction;
use App\Models\GeneralLedger\Account;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Recurring Transactions: a journal, payment or receipt that repeats; each run makes and posts the document on its date, by hand from here or by the daily schedule. */
class RecurringTransactionResource extends ErpResource
{
    public const STATUSES = ['active', 'paused', 'done'];

    /** @return array<string, string> status → its label */
    public static function statuses(): array
    {
        return collect(self::STATUSES)->mapWithKeys(fn (string $s) => [$s => Format::code($s, 'recurring')])->all();
    }

    protected static ?string $model = RecurringTransaction::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $modelLabel = 'Recurring transaction';

    public static function menuKey(): MenuKey
    {
        return MenuKey::RecurringTransactions;
    }

    /**
     * A schedule runs as its author: someone else changing it (its account, its amounts) takes the "edit other
     * users' transactions" right, as changing their documents would.
     */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && self::ownOrAllowed($record);
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && self::ownOrAllowed($record);
    }

    private static function ownOrAllowed(Model $record): bool
    {
        $creator = $record->getAttribute('created_by');

        return $creator === null || (int) $creator === (int) auth()->id() || HakAkses::canSpecial(HakKhusus::EditOthersTransactions);
    }

    private static function isJournal(Get $get, string $path = 'transaction_type'): bool
    {
        return $get($path) === 'journal_voucher';
    }

    private static function isCash(Get $get, string $path = 'transaction_type'): bool
    {
        return in_array($get($path), ['cash_payment', 'cash_receipt'], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100),
                    TextInput::make('category')->label(__('Category'))->maxLength(50)->datalist(fn () => self::categories()),
                    Select::make('transaction_type')->label(__('Document'))->options(RecurringTransaction::types())->required()->native(false)->live(),
                    Select::make('frequency')->label(__('Frequency'))->options(RecurringTransaction::frequencies())->default('monthly')->required()->native(false),
                    DatePicker::make('next_run_on')->label(__('Next run'))->required()->native(false)->default(today())
                        // A first run before today makes back-dated documents: that takes the back-date right.
                        ->rule(fn (?RecurringTransaction $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $changed = $record === null || $record->next_run_on?->toDateString() !== CarbonImmutable::parse($value)->toDateString();
                            if ($changed && CarbonImmutable::parse($value)->lt(today()) && ! app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::BackdateTransactions)) {
                                $fail(__('Dating a transaction before today takes the "back-date transactions" right.'));
                            }
                        }),
                    DatePicker::make('end_on')->label(__('Until'))->native(false)->nullable(),
                    Select::make('status')->label(__('fields.status'))->options(self::statuses())->default('active')->required()->native(false),
                ]),
            Section::make(__('Template'))
                ->description(__('What each run puts on the document; the date is the run date and the number comes from the default series.'))
                ->columns(3)
                ->schema([
                    TextInput::make('template.description')->label(__('Description on the document'))->maxLength(255)->columnSpan(3),
                    Select::make('template.bank_account_id')->label(__('Cash / Bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->native(false)
                        ->visible(fn (Get $get): bool => self::isCash($get))
                        ->required(fn (Get $get): bool => self::isCash($get)),
                    TextInput::make('template.payee')->label(__('Payee'))->maxLength(255)->visible(fn (Get $get): bool => $get('transaction_type') === 'cash_payment'),
                    TextInput::make('template.payer')->label(__('Payer'))->maxLength(255)->visible(fn (Get $get): bool => $get('transaction_type') === 'cash_receipt'),
                    Repeater::make('template.lines')
                        ->label(__('Lines'))
                        ->columns(6)
                        ->columnSpan(3)
                        ->schema([
                            Select::make('account_id')->label(__('Account'))->options(fn () => Account::options())->searchable()->required()->native(false)->columnSpan(2),
                            PricedDocumentForm::money('debit', __('Debit'))->visible(fn (Get $get): bool => self::isJournal($get, '../../../transaction_type')),
                            PricedDocumentForm::money('credit', __('Credit'))->visible(fn (Get $get): bool => self::isJournal($get, '../../../transaction_type')),
                            PricedDocumentForm::money('amount', __('Amount'))->visible(fn (Get $get): bool => ! self::isJournal($get, '../../../transaction_type')),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255)
                                ->columnSpan(fn (Get $get): int => self::isJournal($get, '../../../transaction_type') ? 2 : 3),
                        ])
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel(__('Add line')),
                ]),
        ])->columns(1);
    }

    /** @return array<string, string> the categories in use */
    public static function categories(): array
    {
        return RecurringTransaction::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category', 'category')->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category')->label(__('Category'))->sortable()->placeholder('—'),
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('transaction_type')->label(__('Document'))->sortable()
                    ->formatStateUsing(fn (string $state): string => RecurringTransaction::types()[$state] ?? $state),
                TextColumn::make('frequency')->label(__('Frequency'))
                    ->formatStateUsing(fn (string $state): string => RecurringTransaction::frequencies()[$state] ?? $state),
                Tanggal::make('next_run_on')->label(__('Next run')),
                Tanggal::make('last_run_on')->label(__('Last run'))->placeholder('—'),
                TextColumn::make('run_count')->label(__('Runs'))->alignEnd(),
                TextColumn::make('status')->label(__('fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => Format::code($state, 'recurring'))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'paused' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('next_run_on')
            ->filters([
                SelectFilter::make('transaction_type')->label(__('Document'))->options(RecurringTransaction::types()),
                SelectFilter::make('category')->label(__('Category'))->options(fn () => self::categories()),
                SelectFilter::make('status')->label(__('fields.status'))->options(self::statuses()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('run')
                    ->label(__('Run now'))
                    ->icon('heroicon-m-play')
                    ->color('primary')
                    ->visible(fn ($record): bool => $record->status === 'active' && static::canCreate())
                    ->requiresConfirmation()
                    ->modalDescription(fn ($record): string => __('Makes and posts the document dated :date.', ['date' => Format::date($record->next_run_on)]))
                    ->action(function ($record): void {
                        try {
                            $document = app(RecurringRunner::class)->run($record);
                            Notification::make()->title(__(':number made', ['number' => $document->number]))->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title(__('Cannot run'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringTransactions::route('/'),
            'create' => CreateRecurringTransaction::route('/create'),
            'edit' => EditRecurringTransaction::route('/{record}/edit'),
        ];
    }
}
