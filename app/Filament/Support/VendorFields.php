<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Purchasing\Vendor;
use App\Models\Purchasing\VendorBankAccount;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** The vendor lookup every purchasing document opens with; picking one fills the terms and address from the master. */
final class VendorFields
{
    public static function select(bool $fillsTerms = true): Select
    {
        return Select::make('vendor_id')
            ->label(__('fields.vendor'))
            ->options(fn () => Vendor::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Vendor $v) => [$v->id => "{$v->name} ({$v->number})"]))
            ->searchable()
            ->required()
            ->native(false)
            ->live()
            ->afterStateUpdated(function (Set $set, Get $get, $state) use ($fillsTerms): void {
                $vendor = $state ? Vendor::query()->find($state) : null;
                if ($vendor === null) {
                    return;
                }
                CurrencyFields::forParty($set, $get, $vendor->currency_id);
                if (! $fillsTerms) {
                    return;
                }
                $set('payment_term_id', $vendor->payment_term_id);
                $set('inclusive_tax', (bool) $vendor->default_inc_tax);
                $set('to_address', collect([$vendor->bill_street, $vendor->bill_city, $vendor->bill_province, $vendor->bill_zip_code])->filter()->join(', ') ?: null);
                $set('description', $vendor->default_invoice_desc);
            });
    }

    public static function bankAccount(): Select
    {
        return Select::make('vendor_bank_account_id')
            ->label(__('fields.vendor_bank_account'))
            ->options(fn (Get $get) => $get('vendor_id')
                ? VendorBankAccount::query()->with('bank')->where('vendor_id', $get('vendor_id'))->get()->mapWithKeys(fn ($a) => [$a->id => trim(($a->bank?->name ?? '').' '.$a->bank_account.' '.($a->bank_account_name ? "a/n {$a->bank_account_name}" : ''))])
                : [])
            ->native(false);
    }

    public static function paymentTerm(): Select
    {
        return Select::make('payment_term_id')->label(__('fields.payment_term'))->relationship('paymentTerm', 'name', fn ($query) => $query->where('is_active', true))->preload()->native(false);
    }
}
