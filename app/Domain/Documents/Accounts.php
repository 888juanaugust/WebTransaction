<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;

/** Which account a posting goes to: the party's own, else the item's or its category's, else the preference. */
final class Accounts
{
    public static function payable(?Vendor $vendor): int
    {
        return (int) ($vendor?->payable_account_id ?? app(Preferensi::class)->get(PreferensiKey::PayableAccount));
    }

    public static function vendorDownPayment(?Vendor $vendor): int
    {
        return (int) ($vendor?->down_payment_account_id ?? app(Preferensi::class)->get(PreferensiKey::VendorDownPaymentAccount));
    }

    public static function receivable(?Customer $customer): int
    {
        return (int) ($customer?->receivable_account_id ?? app(Preferensi::class)->get(PreferensiKey::ReceivableAccount));
    }

    public static function customerDownPayment(?Customer $customer): int
    {
        return (int) ($customer?->down_payment_account_id ?? app(Preferensi::class)->get(PreferensiKey::CustomerDownPaymentAccount));
    }

    public static function inventory(Item $item): int
    {
        return (int) ($item->accountFor('inventory')?->id ?? app(Preferensi::class)->get(PreferensiKey::InventoryAccount));
    }

    public static function costOfSales(Item $item, ?Customer $customer = null): int
    {
        return (int) ($customer?->cogs_account_id ?? $item->accountFor('cogs')?->id ?? app(Preferensi::class)->get(PreferensiKey::CostOfSalesAccount));
    }

    public static function sales(Item $item, ?Customer $customer = null): int
    {
        return (int) ($customer?->sales_account_id ?? $item->accountFor('sales')?->id ?? Account::query()->where('no', '4100')->value('id'));
    }

    public static function salesReturn(Item $item, ?Customer $customer = null): int
    {
        return (int) ($customer?->sales_return_account_id ?? $item->accountFor('sales_return')?->id ?? Account::query()->where('no', '4200')->value('id'));
    }

    /** Where opening balances are carried from: 3300 Opening Balance Equity, else the first equity account. */
    public static function openingBalanceEquity(): int
    {
        return (int) (Account::query()->where('no', '3300')->value('id')
            ?? Account::query()->where('account_type', 'equity')->orderBy('no')->value('id'));
    }

    /** Where a realised exchange difference goes: a gain, or a loss. */
    public static function exchangeDifference(int $difference): int
    {
        $key = $difference >= 0 ? PreferensiKey::ExchangeGainAccount : PreferensiKey::ExchangeLossAccount;
        $id = (int) app(Preferensi::class)->get($key);
        if ($id === 0) {
            throw new \RuntimeException(__('Set the account for :what under Preferences → Accounts first.', ['what' => $key->label()]));
        }

        return $id;
    }

    /** A payroll account from Preferences, else the seeded number. */
    public static function payroll(PreferensiKey $key): int
    {
        $fallback = match ($key) {
            PreferensiKey::SalaryExpenseAccount => '6100',
            PreferensiKey::SalaryPayableAccount => '2230',
            PreferensiKey::Pph21PayableAccount => '2220',
            PreferensiKey::BpjsPayableAccount => '2240',
            PreferensiKey::BpjsExpenseAccount => '6110',
            default => throw new \InvalidArgumentException("Not a payroll account: {$key->value}"),
        };
        $id = (int) (app(Preferensi::class)->get($key) ?: Account::query()->where('no', $fallback)->value('id'));
        if ($id === 0) {
            throw new \RuntimeException(__('Set the account for :what under Preferences → Accounts first.', ['what' => $key->label()]));
        }

        return $id;
    }

    public static function giroReceivable(): int
    {
        return (int) app(Preferensi::class)->get(PreferensiKey::GiroReceivableAccount);
    }

    public static function giroPayable(): int
    {
        return (int) app(Preferensi::class)->get(PreferensiKey::GiroPayableAccount);
    }

    public static function salesDiscount(?Customer $customer = null): int
    {
        return (int) ($customer?->sales_discount_account_id ?? app(Preferensi::class)->get(PreferensiKey::SalesDiscountAccount));
    }

    /** What a non-stocked purchase line is expensed to: the item's cost account. */
    public static function purchaseExpense(Item $item): int
    {
        return self::costOfSales($item);
    }

    public static function goodsReceivedNotInvoiced(): int
    {
        return (int) Account::query()->where('no', '2110')->value('id');
    }

    public static function goodsDeliveredNotInvoiced(): int
    {
        return (int) app(Preferensi::class)->get(PreferensiKey::GoodsInTransitAccount);
    }

    public static function inventoryAdjustments(): int
    {
        return (int) Account::query()->where('no', '5200')->value('id');
    }

    public static function purchaseDiscounts(): int
    {
        return (int) Account::query()->where('no', '5300')->value('id');
    }

    public static function vatIn(?TaxCode $code): int
    {
        return (int) ($code?->purchase_tax_account_id ?? Account::query()->where('no', '1400')->value('id'));
    }

    public static function vatOut(?TaxCode $code): int
    {
        return (int) ($code?->sales_tax_account_id ?? Account::query()->where('no', '2200')->value('id'));
    }
}
