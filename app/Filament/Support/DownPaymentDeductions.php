<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Currency\Convert;
use App\Domain\Currency\Currencies;
use App\Domain\Shared\Format;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Model;

/**
 * The down payments an invoice deducts: the party's open ones in the
 * invoice's currency, each deducted by an amount typed in that currency (a
 * foreign invoice keeps it as fc_amount; the base amount is the down
 * payment's carrying value, set when the invoice's totals are refreshed).
 */
final class DownPaymentDeductions
{
    /**
     * @param  class-string<Model>  $class  the down payment model
     * @param  string  $key  the deduction's down payment column
     * @param  string  $party  customer_id or vendor_id
     */
    public static function repeater(string $class, string $key, string $party): Repeater
    {
        return Repeater::make('downPayments')->label(__('Down payments'))
            ->hiddenLabel()
            ->relationship()
            ->table([TableColumn::make(__('Down payment')), TableColumn::make(__('Amount deducted'))->alignment(Alignment::End)])
            ->schema([
                Select::make($key)
                    ->options(fn (Get $get) => $class::query()->where($party, $get('../../'.$party))->whereIn('status', ['pending', 'partial'])->get()
                        ->filter(fn (Model $dp) => Currencies::same($dp->getAttribute('currency_id'), $get('../../currency_id')))
                        ->mapWithKeys(fn (Model $dp) => [$dp->getKey() => $dp->getAttribute('number').' · '.Format::date($dp->getAttribute('trans_date')).' · '.__('open').' '.CurrencyFields::format(self::remaining($dp), $dp->getAttribute('currency_id'))]))
                    ->required()->native(false)->live()
                    ->afterStateUpdated(fn (Set $set, $state) => $set('amount', ($dp = $state ? $class::query()->find($state) : null) ? self::typed($dp) : 0)),
                MoneyInput::inCurrency('amount', fn (Get $get) => CurrencyFields::decimals($get('../../currency_id')))->label(__('Amount'))->default(0)->required()
                    ->rule(fn (): Closure => fn (string $attribute, mixed $value, Closure $fail) => CurrencyFields::isPositive($value) ? null : $fail(__('Enter an amount above zero.'))),
            ])
            ->defaultItems(0)
            ->addActionLabel(__('Deduct a down payment'))
            ->mutateRelationshipDataBeforeFillUsing(fn (array $data, Get $get) => CurrencyFields::fromForeign($data, $get('currency_id'), ['amount' => 'fc_amount']))
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get) => CurrencyFields::toForeign($data, $get('currency_id'), ['amount' => 'fc_amount']))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get) => CurrencyFields::toForeign($data, $get('currency_id'), ['amount' => 'fc_amount']));
    }

    /** What is left of a down payment to deduct, in its own currency. */
    public static function remaining(Model $downPayment): int
    {
        return Currencies::isForeign($downPayment->getAttribute('currency_id'))
            ? (int) $downPayment->getAttribute('fc_total') - (int) $downPayment->getAttribute('fc_used_amount')
            : $downPayment->remaining();
    }

    private static function typed(Model $downPayment): string
    {
        return Convert::typed(self::remaining($downPayment), Currencies::decimals($downPayment->getAttribute('currency_id')));
    }
}
