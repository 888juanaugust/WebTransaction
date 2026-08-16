<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Tax\TaxCalculator;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a confirmed order into an invoice.
 *
 * Every figure comes from the order line snapshots written at `confirmed` —
 * this class never touches the live price list, never re-resolves a price, and
 * never recomputes tax from the order total. Summing the already-rounded line
 * figures is what makes the invoice total equal the sum of the lines printed
 * on it, which is the whole reason tax is stored per line.
 *
 * The customer's tax identity is snapshotted too. A faktur has to show what
 * was true when it was issued, not what the customer record says today.
 */
class InvoiceIssuer
{
    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly DocumentPoster $poster,
    ) {}

    /**
     * Issue the invoice for an order, or return the one it already has.
     *
     * Idempotent: the order → invoice relationship is one-to-one, and calling
     * this twice must not bill a customer twice.
     */
    public function issueFor(Order $order, ?User $actor = null): Invoice
    {
        return DB::transaction(function () use ($order, $actor) {
            $existing = Invoice::query()->where('order_id', $order->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($order->confirmed_at === null) {
                throw new DomainException(
                    "Order {$order->nomor} belum dikonfirmasi, belum bisa difakturkan."
                );
            }

            $lines = $order->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Order {$order->nomor} tidak punya baris.");
            }

            $unpriced = $lines->firstWhere(fn ($line) => $line->priced_at === null);

            if ($unpriced !== null) {
                // Should be impossible after confirm(), but issuing an invoice
                // off a half-priced order is the kind of thing that only shows
                // up in the customer's complaint.
                throw new DomainException(
                    "Baris {$unpriced->sku} pada order {$order->nomor} belum punya snapshot harga."
                );
            }

            // Summed from the stored snapshots, not recomputed.
            $breakdown = $this->tax->forStoredLines($lines);
            $discount = (int) $lines->sum('discount_rupiah');

            $company = $order->company;
            $issuedOn = now();

            $invoice = Invoice::query()->create([
                'nomor' => $this->numbers->nextInvoiceNumber($issuedOn),
                'order_id' => $order->id,
                'company_id' => $company->id,

                // Tax identity as it stands at issue time.
                'npwp' => $company->npwp,
                'nama_wajib_pajak' => $company->nama_wajib_pajak ?? $company->nama,
                'alamat_pajak' => $company->alamat_pajak,

                'subtotal_rupiah' => $breakdown->hargaJual,
                'discount_rupiah' => $discount,
                'dpp_rupiah' => $breakdown->dpp,
                'ppn_rupiah' => $breakdown->ppn,
                'total_rupiah' => $breakdown->total(),

                'issued_on' => $issuedOn->toDateString(),
                // Payment terms are the customer's, agreed when their account
                // was approved. Zero days means due on issue.
                'due_date' => $issuedOn->copy()->addDays($company->payment_terms_days)->toDateString(),

                'kode_transaksi' => $this->tax->kodeTransaksi(),
            ]);

            /*
             * Dr Piutang Usaha / Cr Penjualan + PPN Keluaran, inside this
             * transaction, so the books and the invoice land together.
             */
            $this->poster->invoiceIssued($invoice, $actor);

            $this->audit->log(
                action: 'invoice_issued',
                subject: $invoice,
                newValue: [
                    'nomor' => $invoice->nomor,
                    'order_id' => $order->id,
                    'subtotal_rupiah' => $invoice->subtotal_rupiah,
                    'dpp_rupiah' => $invoice->dpp_rupiah,
                    'ppn_rupiah' => $invoice->ppn_rupiah,
                    'total_rupiah' => $invoice->total_rupiah,
                    'due_date' => $invoice->due_date->toDateString(),
                ],
                actor: $actor,
            );

            return $invoice;
        });
    }
}
