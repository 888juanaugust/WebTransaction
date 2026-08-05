<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use App\Domain\Credit\CreditChecker;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Stock\StockLedger;
use App\Domain\Tax\TaxCalculator;
use App\Models\Cart;
use App\Models\CartItem;

/**
 * What the basket looks like it will cost.
 *
 * Every number this produces is an *indication*. The binding figure is the
 * snapshot each order line takes at `confirmed`, and nothing here is ever
 * written to a database column — this class only reads.
 *
 * It exists so a buyer is not asked to submit an order blind, and so they find
 * out about a short-stocked line or a credit limit on this screen rather than
 * from a phone call two days later. Both of those are warnings, not gates:
 * stock is reserved and credit is checked at `confirmed`, under a row lock, and
 * a decision made here would be stale by then anyway.
 */
class CartTotals
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $tax,
        private readonly StockLedger $stock,
        private readonly CreditChecker $credit,
    ) {}

    public function for(Cart $cart): CartEstimate
    {
        $items = $cart->items()->with('product')->get();
        $company = $cart->company;

        if ($company === null || $items->isEmpty()) {
            return new CartEstimate;
        }

        // One read of the price list for the whole basket.
        $this->prices->prime($company, $items->pluck('sku')->all());

        $lines = [];
        $subtotal = 0;
        $ppn = 0;
        $priced = true;

        foreach ($items as $item) {
            $line = $this->line($cart, $item);
            $lines[$item->id] = $line;

            if ($line->lineTotal === null) {
                $priced = false;

                continue;
            }

            $subtotal += $line->lineTotal;
            $ppn += $this->tax->forLine($line->lineTotal)->ppn;
        }

        $status = $this->credit->status($company);

        return new CartEstimate(
            lines: $lines,
            subtotal: $subtotal,
            ppn: $ppn,
            // The customer pays the selling price plus PPN; DPP is a reporting
            // figure, which is how the order totals do it too.
            total: $subtotal + $ppn,
            fullyPriced: $priced,
            creditAvailable: $status->available(),
        );
    }

    private function line(Cart $cart, CartItem $item): CartLineEstimate
    {
        $qtyBase = $item->baseQuantity();

        if ($qtyBase === null) {
            return new CartLineEstimate(
                problem: 'Satuan tidak cocok dengan produk ini.'
            );
        }

        $resolution = $this->prices->resolve($cart->company, $item->sku, $qtyBase);

        if (! $resolution->isPriced()) {
            return new CartLineEstimate(
                qtyBase: $qtyBase,
                // Never Rp 0 — that reads as free rather than as unpriced.
                problem: 'Harga belum tersedia. Hubungi tim kami.',
            );
        }

        $available = $cart->warehouse_id === null
            ? null
            : $this->stock->available($item->sku, $cart->warehouse_id);

        return new CartLineEstimate(
            qtyBase: $qtyBase,
            unitPrice: $resolution->unitPrice,
            lineTotal: $resolution->unitPrice * $qtyBase,
            available: $available,
            // A warning, not a refusal. Stock moves between now and approval,
            // and the reservation at `confirmed` is the only word that counts.
            problem: ($available !== null && $available < $qtyBase)
                ? "Stok tersedia hanya {$available}."
                : null,
        );
    }
}
