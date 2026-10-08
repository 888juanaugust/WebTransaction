<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets;

use App\Domain\Documents\Accounts;
use App\Domain\Posting\Contracts\Blocker;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Shared\Format;
use App\Models\FixedAssets\FixedAsset;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseInvoiceLine;
use Illuminate\Database\Eloquent\Model;

/**
 * A fixed asset recorded from a purchase invoice line: the asset starts
 * with the line's name, date, quantity, branch and net amount, and its one
 * expenditure is the account the invoice debited for the line, so the asset's
 * posting moves that amount onto the asset account. While the asset exists
 * the invoice cannot change or go.
 */
final class AssetFromBill implements Blocker
{
    /** @return array<string, mixed> the asset form's starting values */
    public static function prefill(PurchaseInvoiceLine $line): array
    {
        $line->loadMissing(['purchaseInvoice', 'item.category']);
        $bill = $line->purchaseInvoice;
        $account = $line->item->item_type->isStocked() ? Accounts::inventory($line->item) : Accounts::purchaseExpense($line->item);

        return [
            'name' => $line->memo ?: $line->item->name,
            'trans_date' => $bill->trans_date->toDateString(),
            'usage_date' => $bill->trans_date->toDateString(),
            'quantity' => (string) $line->base_quantity,
            'branch_id' => $bill->branch_id,
            'purchase_invoice_line_id' => $line->id,
            'notes' => __('From purchase invoice :number', ['number' => $bill->number]),
            'expenditures' => [[
                'account_id' => $account,
                'description' => __('Purchase invoice :number', ['number' => $bill->number]),
                'trans_date' => $bill->trans_date->toDateString(),
                'amount' => $line->netAmount(),
            ]],
        ];
    }

    /** @return array<int, string> line id → label, for the invoice's lines no asset was recorded from */
    public static function openLines(PurchaseInvoice $bill): array
    {
        $taken = FixedAsset::query()->whereNotNull('purchase_invoice_line_id')->pluck('purchase_invoice_line_id')->all();

        return $bill->lines()->with(['item', 'purchaseInvoice'])->whereNotIn('id', $taken)->get()
            ->mapWithKeys(fn (PurchaseInvoiceLine $l) => [$l->id => ($l->memo ?: $l->item->name).' · '.Format::quantity($l->base_quantity).' · '.Format::number($l->netAmount())])
            ->all();
    }

    public function blocks(Postable|Model $document): ?string
    {
        if (! $document instanceof PurchaseInvoice) {
            return null;
        }
        $asset = FixedAsset::query()->whereIn('purchase_invoice_line_id', $document->lines()->select('id'))->value('number');

        return $asset ? __('fixed asset :number was recorded from it; delete the asset first.', ['number' => $asset]) : null;
    }
}
