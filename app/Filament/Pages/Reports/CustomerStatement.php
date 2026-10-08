<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Sales\Customer;

/** Customer Statement (customer-statement). */
class CustomerStatement extends PartyStatement
{
    public static function reportKey(): string
    {
        return 'customer-statement';
    }

    public static function title(): string
    {
        return __('Customer Statement');
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return __('One customer\'s invoices, down payments, opening balances, returns and receipts in the period, with the balance brought forward and the running balance owed.');
    }

    protected static function party(): string
    {
        return 'customer';
    }

    protected static function parties(): array
    {
        return Customer::query()->orderBy('name')->get()->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->number} · {$c->name}"])->all();
    }

    protected static function partyLabel(): string
    {
        return __('Customer');
    }

    protected static function chargeLabel(): string
    {
        return __('Invoiced');
    }

    protected static function paymentLabel(): string
    {
        return __('Received');
    }
}
