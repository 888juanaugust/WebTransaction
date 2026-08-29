<?php

declare(strict_types=1);

namespace App\Domain\Quotes;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Tax\TaxCalculator;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penawaran: the offer a salesperson puts in writing before there is an
 * order — the missing caller of resolvePrice that CLAUDE.md promised from
 * the start.
 *
 * Prices snapshot at issue, through the same resolver as everything else,
 * because a quote is a statement about numbers on a date and must never be
 * a number somebody typed. The order it may become prices itself **again**
 * at `confirmed` — invariant 3 is untouched — so a price list published
 * between quote and order shows up as a difference at approval instead of
 * silently honouring either side. The quote is the promise; the snapshot
 * at confirmed is the contract.
 *
 * Expiry is derived from `valid_until`, never stored, and bites where it
 * matters: an expired quote cannot be accepted and cannot become an order.
 */
class QuotationFlow
{
    /** Two weeks, unless the salesperson says otherwise. */
    public const BERLAKU_HARI = 14;

    public function __construct(
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $tax,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{sku: string, qty: int, unit: string}>  $lines
     */
    public function draft(
        Company $company,
        array $lines,
        User $actor,
        ?Carbon $validUntil = null,
        ?string $catatan = null,
    ): Quotation {
        if (! $actor->role()->canCreateOrders()) {
            throw new DomainException('Anda tidak berhak membuat penawaran.');
        }

        if ($lines === []) {
            throw new DomainException('Penawaran tanpa barang bukan penawaran.');
        }

        return DB::transaction(function () use ($company, $lines, $actor, $validUntil, $catatan) {
            $pricedOn = now();

            $quotation = Quotation::create([
                'nomor' => $this->numbers->next('quotation', 'PEN'),
                'company_id' => $company->id,
                'status' => Quotation::STATUS_DRAFT,
                'valid_until' => ($validUntil ?? now()->addDays(self::BERLAKU_HARI))->toDateString(),
                'catatan' => $catatan,
                'created_by' => $actor->id,
            ]);

            $products = Product::query()
                ->whereIn('kode', array_column($lines, 'sku'))
                ->get()
                ->keyBy('kode');

            $this->prices->prime($company, array_column($lines, 'sku'), $pricedOn);

            $subtotal = 0;
            $discount = 0;
            $dpp = 0;
            $ppn = 0;

            foreach (array_values($lines) as $i => $line) {
                $product = $products[$line['sku']]
                    ?? throw new DomainException("SKU {$line['sku']} tidak dikenal.");

                $qtyBase = strtoupper($line['unit']) === 'CTN'
                    ? $line['qty'] * (int) $product->qty_per_ctn
                    : $line['qty'];

                $resolution = $this->prices->resolve($company, $product->kode, $qtyBase, $pricedOn);
                $unitPrice = $resolution->requireUnitPrice();
                $lineTotal = $unitPrice * $qtyBase;
                $listTotal = ($resolution->listPrice ?? $unitPrice) * $qtyBase;
                $lineDiscount = max(0, $listTotal - $lineTotal);
                $breakdown = $this->tax->forLine($lineTotal);

                QuotationLine::create([
                    'quotation_id' => $quotation->id,
                    'urutan' => $i + 1,
                    'sku' => $product->kode,
                    'ordered_unit' => strtoupper($line['unit']),
                    'ordered_qty' => $line['qty'],
                    'qty_base' => $qtyBase,
                    'qty_per_ctn_snapshot' => (int) $product->qty_per_ctn,
                    'satuan_dasar_snapshot' => (string) $product->satuan_dasar,
                    'unit_price_rupiah' => $unitPrice,
                    'discount_rupiah' => $lineDiscount,
                    'line_total_rupiah' => $lineTotal,
                    'dpp_rupiah' => $breakdown->dpp,
                    'ppn_rupiah' => $breakdown->ppn,
                    'price_reason' => $resolution->reason->value,
                    'merk_snapshot' => $product->merk,
                    'description_snapshot' => $product->description,
                ]);

                $subtotal += $lineTotal;
                $discount += $lineDiscount;
                $dpp += $breakdown->dpp;
                $ppn += $breakdown->ppn;
            }

            $quotation->forceFill([
                'subtotal_rupiah' => $subtotal,
                'discount_rupiah' => $discount,
                'dpp_rupiah' => $dpp,
                'ppn_rupiah' => $ppn,
                'total_rupiah' => $subtotal + $ppn,
            ])->save();

            $this->audit->log(
                action: 'quotation_drafted',
                subject: $quotation,
                newValue: ['company_id' => $company->id, 'total_rupiah' => $subtotal + $ppn],
                actor: $actor,
            );

            return $quotation->refresh();
        });
    }

    public function send(Quotation $quotation, User $actor): Quotation
    {
        $this->mustBe($quotation, Quotation::STATUS_DRAFT, 'dikirim');

        $quotation->forceFill([
            'status' => Quotation::STATUS_TERKIRIM,
            'sent_at' => now(),
        ])->save();

        $this->audit->log(action: 'quotation_sent', subject: $quotation, actor: $actor);

        return $quotation;
    }

    public function accept(Quotation $quotation, User $actor): Quotation
    {
        $this->mustBe($quotation, Quotation::STATUS_TERKIRIM, 'diterima');

        if ($quotation->isExpired()) {
            throw new DomainException(
                'Penawaran sudah kedaluwarsa pada '.$quotation->valid_until->format('d/m/Y')
                .'. Buat penawaran baru — harga lama bukan janji yang masih hidup.'
            );
        }

        $quotation->forceFill([
            'status' => Quotation::STATUS_DITERIMA,
            'accepted_at' => now(),
        ])->save();

        $this->audit->log(action: 'quotation_accepted', subject: $quotation, actor: $actor);

        return $quotation;
    }

    public function cancel(Quotation $quotation, User $actor, ?string $alasan = null): Quotation
    {
        if (! in_array($quotation->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_TERKIRIM], true)) {
            throw new DomainException("Penawaran berstatus {$quotation->status} tidak bisa dibatalkan.");
        }

        $quotation->forceFill(['status' => Quotation::STATUS_BATAL])->save();

        $this->audit->log(
            action: 'quotation_cancelled',
            subject: $quotation,
            actor: $actor,
            alasan: $alasan,
        );

        return $quotation;
    }

    /**
     * The accepted quote becomes a draft order — once, and at most once.
     *
     * The order copies the goods, never the prices: lines carry sku and
     * quantity only, and the order re-resolves at `confirmed` like every
     * order does (invariant 3). If the price list moved since the quote,
     * the approval screen shows today's numbers next to a note naming the
     * quote — a person decides which promise stands, not this method.
     */
    public function toOrder(Quotation $quotation, Warehouse $gudang, User $actor): Order
    {
        if (! $actor->role()->canCreateOrders()) {
            throw new DomainException('Anda tidak berhak membuat order.');
        }

        $this->mustBe($quotation, Quotation::STATUS_DITERIMA, 'dijadikan order');

        if ($quotation->order_id !== null) {
            throw new DomainException(
                "Penawaran ini sudah menjadi order (lihat #{$quotation->order_id}). "
                .'Satu penawaran, satu order.'
            );
        }

        return DB::transaction(function () use ($quotation, $gudang, $actor) {
            $order = Order::create([
                'nomor' => $this->numbers->nextOrderNumber(),
                'company_id' => $quotation->company_id,
                'warehouse_id' => $gudang->id,
                'created_by' => $actor->id,
                'sales_user_id' => $quotation->company->sales_user_id,
                'catatan' => trim("Dari penawaran {$quotation->nomor}. ".($quotation->catatan ?? '')),
            ]);

            foreach ($quotation->lines as $line) {
                OrderLine::create([
                    'order_id' => $order->id,
                    'urutan' => $line->urutan,
                    'sku' => $line->sku,
                    'ordered_unit' => $line->ordered_unit,
                    'ordered_qty' => $line->ordered_qty,
                    'qty_base' => $line->qty_base,
                    'qty_per_ctn_snapshot' => $line->qty_per_ctn_snapshot,
                    'satuan_dasar_snapshot' => $line->satuan_dasar_snapshot,
                ]);
            }

            $quotation->forceFill(['order_id' => $order->id])->save();

            $this->audit->log(
                action: 'quotation_converted',
                subject: $quotation,
                newValue: ['order_id' => $order->id, 'nomor_order' => $order->nomor],
                actor: $actor,
            );

            return $order;
        });
    }

    private function mustBe(Quotation $quotation, string $status, string $kata): void
    {
        if ($quotation->status !== $status) {
            throw new DomainException(
                "Penawaran berstatus {$quotation->status} tidak bisa {$kata}."
            );
        }
    }
}
