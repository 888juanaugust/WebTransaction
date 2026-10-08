<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\GeneralLedger\Posting;
use App\Models\Inventory\Item;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseInvoiceLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * "Last purchase price is updated by purchase invoices" (Preferences): an
 * item's purchase price follows its latest purchase invoice, net of discount
 * and included tax, per base unit. Only invoices dated on or after the
 * cutoff date count; a back-dated invoice older than the latest changes
 * nothing; deleting the latest invoice falls back to the one before it.
 * Registered as a posting writer by the purchasing module.
 */
final class LastPurchasePrice
{
    public function __construct(private readonly Preferensi $prefs) {}

    public function afterPosting(Posting $posting): void
    {
        if ($posting->document_type === 'purchase_invoice') {
            $this->refresh($this->itemsOf((int) $posting->document_id));
        }
    }

    /** A superseded posting is followed by the invoice's new one (handled above); only an invoice going away is left out. */
    public function afterUnposting(Posting $posting): void
    {
        if ($posting->document_type !== 'purchase_invoice') {
            return;
        }
        $reposted = Posting::query()->whereNull('superseded_at')->where('document_type', $posting->document_type)->where('document_id', $posting->document_id)->exists();
        if (! $reposted) {
            $this->refresh($this->itemsOf((int) $posting->document_id), exceptInvoiceId: (int) $posting->document_id);
        }
    }

    /** @param  list<int>  $itemIds */
    public function refresh(array $itemIds, ?int $exceptInvoiceId = null): void
    {
        if (! $this->prefs->get(PreferensiKey::LastPriceUpdatedByBill)) {
            return;
        }
        $cutoff = $this->prefs->get(PreferensiKey::LastPriceCutoffDate);
        foreach (array_unique($itemIds) as $itemId) {
            $line = PurchaseInvoiceLine::query()
                ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_lines.purchase_invoice_id')
                ->where('purchase_invoice_lines.item_id', $itemId)
                ->where('purchase_invoice_lines.base_quantity', '>', 0)
                ->when($cutoff, fn ($q) => $q->where('purchase_invoices.trans_date', '>=', $cutoff))
                ->when($exceptInvoiceId, fn ($q) => $q->where('purchase_invoices.id', '!=', $exceptInvoiceId))
                ->orderByDesc('purchase_invoices.trans_date')->orderByDesc('purchase_invoices.id')->orderByDesc('purchase_invoice_lines.id')
                ->select('purchase_invoice_lines.*')
                ->with('purchaseInvoice')
                ->first();
            if ($line === null) {
                continue;
            }
            $perBase = BigDecimal::of($line->netAmount())->dividedBy((string) $line->base_quantity, 0, RoundingMode::HalfUp)->toInt();
            Item::query()->whereKey($itemId)->update(['purchase_price' => $perBase]);
        }
    }

    /** @return list<int> */
    private function itemsOf(int $invoiceId): array
    {
        return PurchaseInvoice::query()->find($invoiceId)?->lines()->pluck('item_id')->map(fn ($id) => (int) $id)->all() ?? [];
    }
}
