<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Purchasing\Vendor;

/** Customer Statement (customer-statement). */
class VendorStatement extends PartyStatement
{
    public static function reportKey(): string
    {
        return 'vendor-statement';
    }

    public static function title(): string
    {
        return __('Vendor Statement');
    }

    public static function group(): string
    {
        return 'Purchasing';
    }

    public static function description(): string
    {
        return __('One vendor\'s bills, down payments, opening balances, returns and payments in the period, with the balance brought forward and the running balance owed.');
    }

    protected static function party(): string
    {
        return 'vendor';
    }

    protected static function parties(): array
    {
        return Vendor::query()->orderBy('name')->get()->mapWithKeys(fn (Vendor $c) => [$c->id => "{$c->number} · {$c->name}"])->all();
    }

    protected static function partyLabel(): string
    {
        return __('Vendor');
    }

    protected static function chargeLabel(): string
    {
        return __('Billed');
    }

    protected static function paymentLabel(): string
    {
        return __('Paid');
    }
}
