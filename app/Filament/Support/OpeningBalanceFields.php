<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Company\DataStart;
use App\Domain\Company\OpeningBalances;
use App\Domain\Currency\Currencies;
use App\Models\Company\OpeningBalance;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The Opening balance tab of a customer or vendor: the invoices still open
 * at the data start date. Each row posts on the data start against Opening
 * Balance Equity and is settled by receipts or payments; a settled row is
 * locked. The rows are saved through the document repository, never as a
 * plain relationship.
 */
final class OpeningBalanceFields
{
    public static function repeater(string $addLabel): Repeater
    {
        $currencies = Currencies::enabled();

        return Repeater::make('openingBalances')->label(__('Opening balances'))
            ->hiddenLabel()
            ->relationship()
            ->orderColumn('sort')
            ->table([
                TableColumn::make(__('Invoice date')),
                TableColumn::make(__('Due date')),
                ...($currencies ? [TableColumn::make(__('Currency')), TableColumn::make(__('Rate'))] : []),
                TableColumn::make(__('Amount')),
                TableColumn::make(__('Payment term')),
                TableColumn::make(__('Number')),
                TableColumn::make(__('Description')),
                TableColumn::make(__('Open')),
            ])
            ->schema([
                DatePicker::make('document_date')->required()->native(false)->default(fn () => DataStart::openingDate())
                    ->maxDate(fn () => DataStart::date()),
                DatePicker::make('due_date')->native(false)->placeholder(__('From the payment term')),
                ...($currencies ? [
                    Select::make('currency_id')->options(fn () => Currencies::foreignOptions())->placeholder(fn () => Currencies::base()?->code)->native(false)->live()
                        ->default(fn (Get $get) => $get('../../currency_id'))
                        ->afterStateUpdated(fn (Set $set, $state) => $set('exchange_rate', CurrencyFields::state($state, DataStart::openingDate())['exchange_rate'])),
                    TextInput::make('exchange_rate')->numeric()->minValue(0.00000001)->default(1)->disabled(fn (Get $get) => ! Currencies::isForeign($get('currency_id'))),
                ] : []),
                MoneyInput::inCurrency('amount', fn (Get $get) => Currencies::decimals($get('currency_id')))->required()->prefix(fn (Get $get) => CurrencyFields::symbol($get('currency_id'))),
                Select::make('payment_term_id')->relationship('paymentTerm', 'name')->native(false),
                TextInput::make('number')->maxLength(40),
                TextInput::make('description')->label(__('Description'))->maxLength(255),
                Placeholder::make('open')->label(__('Open'))->hiddenLabel()->content(fn (?OpeningBalance $record): string => $record === null
                    ? '—'
                    : ($record->paid_amount > 0 ? CurrencyFields::number(SettlementLineFields::open($record), $record->currency_id) : __('Unpaid'))),
            ])
            ->helperText(__('Each row posts on the data start date against Opening Balance Equity; receipts and payments settle it like an invoice.'))
            ->addActionLabel($addLabel)
            ->defaultItems(0)
            ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => CurrencyFields::fromForeign($data, $data['currency_id'] ?? null, ['amount' => 'fc_amount']))
            ->saveRelationshipsUsing(function (Repeater $component, Model $record): void {
                try {
                    app(OpeningBalances::class)->sync($record, (array) $component->getRawState());
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Opening balances not saved'))->body($e->getMessage())->danger()->persistent()->send();

                    throw new Halt;
                }
            });
    }
}
