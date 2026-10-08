<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\MemorizedTransactions;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\CashBank\CashPayments\CashPaymentResource;
use App\Filament\Resources\CashBank\CashReceipts\CashReceiptResource;
use App\Filament\Resources\Company\MemorizedTransactions\Pages\ManageMemorizedTransactions;
use App\Filament\Resources\GeneralLedger\JournalVouchers\JournalVoucherResource;
use App\Filament\Support\MasterResource;
use App\Models\Company\MemorizedTransaction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Memorized Transactions: a form saved as a template, used again from the document's create page. */
class MemorizedTransactionResource extends MasterResource
{
    public const JOURNAL = 'journal_voucher';

    public const PAYMENT = 'cash_bank_voucher_payment';

    public const RECEIPT = 'cash_bank_voucher_receipt';

    protected static ?string $model = MemorizedTransaction::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static ?string $modelLabel = 'Memorized transaction';

    public static function menuKey(): MenuKey
    {
        return MenuKey::MemorizedTransactions;
    }

    /** @return array<string, string> the document types a form can be memorized from */
    public static function typeOptions(): array
    {
        return [self::JOURNAL => __('Journal voucher'), self::PAYMENT => __('Payment'), self::RECEIPT => __('Receipt')];
    }

    public static function typeLabel(string $type): string
    {
        return self::typeOptions()[$type] ?? TransactionType::tryFrom($type)?->getLabel() ?? $type;
    }

    /** The create page that starts from the template, when the type has one. */
    public static function useUrl(MemorizedTransaction $record): ?string
    {
        return match ($record->transaction_type) {
            self::JOURNAL => JournalVoucherResource::getUrl('create', ['memorized' => $record->id]),
            self::PAYMENT => CashPaymentResource::getUrl('create', ['memorized' => $record->id]),
            self::RECEIPT => CashReceiptResource::getUrl('create', ['memorized' => $record->id]),
            default => null,
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100),
            Select::make('transaction_type')->label(__('Document'))->options(self::typeOptions())->disabled()->dehydrated(false),
            Toggle::make('used_all_user')->label(__('All users'))->default(true)->live(),
            CheckboxList::make('users')
                ->label(__('fields.users'))
                ->relationship('users', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                ->columns(2)
                ->searchable()
                ->visible(fn (Get $get): bool => ! $get('used_all_user')),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('users'))
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('transaction_type')->label(__('Document'))->sortable()
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state)),
                self::usersColumn(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('transaction_type')->label(__('Document'))->options(self::typeOptions()),
            ])
            ->recordActions([
                Action::make('use')
                    ->label(__('Use'))
                    ->icon('heroicon-m-document-duplicate')
                    ->color('primary')
                    ->url(fn (MemorizedTransaction $record): ?string => self::useUrl($record))
                    ->visible(fn (MemorizedTransaction $record): bool => self::useUrl($record) !== null),
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMemorizedTransactions::route('/')];
    }
}
