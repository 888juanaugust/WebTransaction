<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Sales\CreditCheck;
use App\Domain\Shared\Format;
use App\Models\Company\Employee;
use App\Models\Sales\Customer;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** The customer lookup every sales document opens with; picking one fills terms, address, tax default and the salesperson. */
final class CustomerFields
{
    public static function select(bool $fillsTerms = true, ?string $label = null): Select
    {
        return Select::make('customer_id')
            ->label($label ?? __('fields.customer'))
            ->options(fn () => Customer::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->name} ({$c->number})"]))
            ->searchable()
            ->required()
            ->native(false)
            ->live()
            ->hint(function ($state): ?string {
                $customer = $state ? Customer::query()->find($state) : null;
                $check = app(CreditCheck::class);

                return $customer && HakAkses::canSpecial(HakKhusus::SeeCreditData) && $check->needsNotice($customer) ? __('Overdue: an invoice is older than :days days', ['days' => $check->noticeDays()]) : null;
            })
            ->hintColor('danger')
            ->afterStateUpdated(function (Set $set, Get $get, $state) use ($fillsTerms): void {
                $customer = $state ? Customer::query()->find($state) : null;
                if ($customer === null) {
                    return;
                }
                CurrencyFields::forParty($set, $get, $customer->currency_id);
                if (! $fillsTerms) {
                    return;
                }
                $set('payment_term_id', $customer->payment_term_id);
                $set('inclusive_tax', (bool) $customer->default_inc_tax);
                $set('to_address', $customer->ship_same_as_bill ? $customer->billAddress() : collect([$customer->ship_street, $customer->ship_city, $customer->ship_province, $customer->ship_zip_code])->filter()->join(', '));
                $set('description', $customer->default_invoice_desc);
                $set('discount_percent', (string) $customer->default_sales_disc);
            });
    }

    public static function paymentTerm(): Select
    {
        return Select::make('payment_term_id')->label(__('fields.payment_term'))->relationship('paymentTerm', 'name', fn ($query) => $query->where('is_active', true))->preload()->native(false);
    }

    public static function salesman(): Select
    {
        return Select::make('salesman_id')->label(__('fields.salesman'))->options(fn () => Employee::query()->salesmen()->orderBy('name')->pluck('name', 'id'))->native(false)->searchable();
    }

    public static function exposureSummary(Customer $customer): string
    {
        $check = app(CreditCheck::class);

        return __('Open: :open · Orders: :orders', ['open' => Format::rupiah($check->exposure($customer)), 'orders' => Format::rupiah($check->openOrders($customer))]);
    }
}
