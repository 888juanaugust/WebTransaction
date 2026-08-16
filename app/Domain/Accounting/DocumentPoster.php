<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Money;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\StockMovement;
use App\Models\SupplierBill;
use App\Models\SupplierPaymentEntry;
use App\Models\User;

/**
 * Every posting rule in the system, in one file.
 *
 * The same reasoning as resolvePrice(): a rule that lives in six places is six
 * rules, and the day they disagree is the day a report stops tying to the
 * subledger it came from. So there is exactly one statement of what each
 * document does to the books, and it is here where an accountant can read all
 * of it in one sitting.
 *
 * Each method is called from inside the document service's own transaction, so
 * the journal lands with the document or not at all. Each returns the entry —
 * or null where there is genuinely nothing to post — and each is idempotent,
 * because Ledger::post() is.
 *
 * The control accounts these rules maintain, and what proves each one:
 *
 *   Piutang Usaha           = total outstanding customer invoices
 *   Utang Usaha             = total outstanding supplier bills
 *   Persediaan              = total value in product_costs
 *   Utang Belum Ditagih     = goods received and not yet billed
 *
 * `LedgerReconciliation` checks all four against their subledgers. If one
 * drifts, a rule below is wrong.
 */
class DocumentPoster
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Invoice issued: the customer now owes us.
     *
     *   Dr Piutang Usaha        total
     *     Cr Penjualan          harga jual, net of discount
     *     Cr PPN Keluaran       tax collected on their behalf
     *
     * Revenue is booked net of discount rather than gross with a contra
     * account. That is a real choice: it means the discount given away is not
     * a number on the profit and loss, only a column on the invoice. Add
     * Potongan Penjualan if that ever needs to be visible.
     *
     * Note the timing. An order is invoiced when it moves to
     * `awaiting_payment`, which is *before* it ships — so revenue is
     * recognised here and the cost of it lands later, possibly in the next
     * month. That is a policy question for the accountant, not a bug: see
     * docs/MAP.md.
     */
    public function invoiceIssued(Invoice $invoice, ?User $actor = null): JournalEntry
    {
        $company = $invoice->company;

        $draft = JournalDraft::for(
            $invoice,
            JournalEntry::JENIS_PENJUALAN,
            "Faktur {$invoice->nomor}",
            $invoice->issued_on,
        )
            ->debit(AccountCode::PIUTANG_USAHA, (int) $invoice->total_rupiah, $company?->nama, company: $company)
            ->kredit(AccountCode::PENJUALAN, (int) $invoice->subtotal_rupiah, company: $company)
            ->kredit(AccountCode::PPN_KELUARAN, (int) $invoice->ppn_rupiah, 'PPN keluaran');

        return $this->ledger->post($draft, $actor);
    }

    /**
     * Order shipped: the goods leave and become a cost.
     *
     *   Dr Harga Pokok Penjualan   what the goods cost us
     *     Cr Persediaan
     *
     * At the cost already frozen onto the stock movements, not at today's
     * average. A receipt arriving tomorrow moves the average for everything
     * after it and must not move this.
     *
     * Returns null when nothing was valued — stock can be shipped that was
     * never costed, if it was entered before the costing existed. That is
     * visible through InventoryValuation::unvaluedIssues() rather than being
     * papered over with a zero entry.
     *
     * @param  list<StockMovement>  $movements
     */
    public function shipmentCosted(Order $order, array $movements, ?User $actor = null): ?JournalEntry
    {
        $cost = 0;

        foreach ($movements as $movement) {
            if ($movement->value_rupiah === null) {
                continue;
            }

            // Outbound movements carry a negative value; cost of sales is the
            // magnitude of what left.
            $cost += -(int) $movement->value_rupiah;
        }

        if ($cost === 0) {
            return null;
        }

        $draft = JournalDraft::for($order, JournalEntry::JENIS_HPP, "HPP pengiriman {$order->nomor}")
            ->debit(AccountCode::HARGA_POKOK_PENJUALAN, $cost, company: $order->company)
            ->kredit(AccountCode::PERSEDIAAN, $cost);

        return $this->ledger->post($draft, $actor);
    }

    /**
     * Goods received: stock arrives before the bill does.
     *
     *   Dr Persediaan              what the receipt valued them at
     *     Cr Utang Belum Ditagih
     *
     * The credit is not Utang Usaha, because we do not yet owe anybody a
     * specific amount — the supplier has not billed. Booking it straight to
     * payables would mean guessing the bill, and then correcting the guess.
     */
    public function goodsReceived(GoodsReceipt $receipt, User $actor): ?JournalEntry
    {
        $value = (int) $receipt->total_value_rupiah;

        if ($value === 0) {
            return null;
        }

        $draft = JournalDraft::for(
            $receipt,
            JournalEntry::JENIS_PENERIMAAN_BARANG,
            "Penerimaan barang {$receipt->nomor}",
            $receipt->tanggal_terima ?? $receipt->posted_at,
        )
            ->debit(AccountCode::PERSEDIAAN, $value, supplier: $receipt->supplier)
            ->kredit(AccountCode::UTANG_BELUM_DITAGIH, $value, supplier: $receipt->supplier);

        return $this->ledger->post($draft, $actor);
    }

    /**
     * Supplier bill posted: the accrual becomes a real debt.
     *
     *   Dr Utang Belum Ditagih         at the cost the goods were received at
     *   Dr Selisih Harga Pembelian     whatever the supplier charged on top
     *   Dr PPN Masukan                 if there is a faktur pajak
     *     Cr Utang Usaha               the total we now owe
     *
     * The variance line is the point of this rule. Inventory keeps the cost it
     * was valued at when the goods arrived; if the bill disagrees, the
     * difference is an expense of this period rather than a silent rewrite of
     * stock value. Without it, a supplier's price creep would quietly restate
     * the balance sheet and last month's margin along with it.
     *
     * Each bill line points at the receipt line it bills, so the amount to
     * clear is exact rather than apportioned. A line with no receipt behind it
     * — a bill that arrived before the goods — clears nothing and accrues the
     * whole amount against Utang Belum Ditagih, which then goes contra until
     * the delivery turns up. That is what the account is for.
     */
    public function supplierBillPosted(SupplierBill $bill, User $actor): JournalEntry
    {
        $bill->loadMissing('lines.goodsReceiptLine', 'supplier');

        $supplier = $bill->supplier;
        $grni = 0;
        $variance = 0;

        foreach ($bill->lines as $line) {
            $billed = (int) $line->line_total_rupiah;
            $receiptLine = $line->goodsReceiptLine;

            if ($receiptLine === null || $receiptLine->qty_base <= 0) {
                $grni += $billed;

                continue;
            }

            // What this quantity went into stock at.
            $received = Money::mulDiv(
                (int) $receiptLine->line_value_rupiah,
                (int) $line->qty_base,
                (int) $receiptLine->qty_base,
            );

            $grni += $received;
            $variance += $billed - $received;
        }

        $draft = JournalDraft::for(
            $bill,
            JournalEntry::JENIS_TAGIHAN_PEMASOK,
            "Tagihan pemasok {$bill->nomor}",
            $bill->tanggal_faktur ?? $bill->posted_at,
        )
            ->debit(AccountCode::UTANG_BELUM_DITAGIH, $grni, supplier: $supplier)
            ->debitSigned(
                AccountCode::SELISIH_HARGA_PEMBELIAN,
                $variance,
                'Selisih harga terima vs tagihan',
                supplier: $supplier,
            );

        $this->addInputVat($draft, $bill);

        $draft->kredit(
            AccountCode::UTANG_USAHA,
            (int) $bill->total_rupiah,
            $bill->nomor_faktur_supplier,
            supplier: $supplier,
        );

        return $this->ledger->post($draft, $actor);
    }

    /**
     * Money in from a customer.
     *
     *   Dr Bank        what arrived
     *     Cr Piutang Usaha
     *
     * Reversals are their own PaymentEntry row with a negative amount, so they
     * post their own entry with both sides the other way round — the same
     * append-only shape the payment ledger already has.
     *
     * Always Bank, never Kas. Gateway payments are Virtual Account transfers,
     * so those are unambiguous. A payment finance keys in by hand could be a
     * transfer or cash over the counter and nothing on the row says which —
     * it is booked to Bank because nearly all of them are, and reclassified
     * with a manual journal on the rare occasion it is not. Kas exists in the
     * chart for that journal to move it to.
     */
    public function customerPaymentReceived(PaymentEntry $entry, ?User $actor = null): JournalEntry
    {
        $amount = (int) $entry->amount_rupiah;
        $company = $entry->company;

        $draft = JournalDraft::for(
            $entry,
            JournalEntry::JENIS_PEMBAYARAN_PELANGGAN,
            $this->paymentDescription($entry),
            $entry->paid_at,
        )
            ->debitSigned(AccountCode::BANK, $amount, $entry->gateway_reference)
            ->kreditSigned(AccountCode::PIUTANG_USAHA, $amount, $company?->nama, company: $company);

        return $this->ledger->post($draft, $actor);
    }

    /**
     * Money out to a supplier.
     *
     *   Dr Utang Usaha
     *     Cr Bank
     */
    public function supplierPaymentMade(SupplierPaymentEntry $entry, ?User $actor = null): JournalEntry
    {
        $amount = (int) $entry->amount_rupiah;
        $supplier = $entry->supplier;

        $keterangan = $entry->kind === SupplierPaymentEntry::KIND_REVERSAL
            ? "Pembalikan pembayaran pemasok #{$entry->reverses_entry_id}"
            : "Pembayaran ke pemasok {$supplier?->nama}";

        $draft = JournalDraft::for(
            $entry,
            JournalEntry::JENIS_PEMBAYARAN_PEMASOK,
            $keterangan,
            $entry->paid_at,
        )
            ->debitSigned(AccountCode::UTANG_USAHA, $amount, $entry->referensi, supplier: $supplier)
            ->kreditSigned(AccountCode::BANK, $amount, supplier: $supplier);

        return $this->ledger->post($draft, $actor);
    }

    /**
     * Input VAT, but only where it is actually creditable.
     *
     * PPN paid to a supplier is an asset — it comes off what we hand the state
     * — but only against a faktur pajak. Without one it is simply part of what
     * the purchase cost.
     *
     * It goes to Beban Operasional rather than into Persediaan, which is the
     * textbook treatment for non-creditable VAT on goods. Adding it to
     * inventory would put the ledger's Persediaan above product_costs, and a
     * control account that cannot be proved against its subledger is worse
     * than one that is slightly conservative. Worth raising with the
     * accountant: it is the one place these rules knowingly diverge.
     */
    private function addInputVat(JournalDraft $draft, SupplierBill $bill): void
    {
        $ppn = (int) $bill->ppn_rupiah;

        if ($ppn === 0) {
            return;
        }

        if (filled($bill->nomor_faktur_pajak)) {
            $draft->debit(
                AccountCode::PPN_MASUKAN,
                $ppn,
                "Faktur pajak {$bill->nomor_faktur_pajak}",
                supplier: $bill->supplier,
            );

            return;
        }

        $draft->debit(
            AccountCode::BEBAN_OPERASIONAL,
            $ppn,
            'PPN tanpa faktur pajak — tidak dapat dikreditkan',
            supplier: $bill->supplier,
        );
    }

    private function paymentDescription(PaymentEntry $entry): string
    {
        if ($entry->kind === PaymentEntry::KIND_REVERSAL) {
            return "Pembalikan pembayaran #{$entry->reverses_entry_id}";
        }

        $invoice = $entry->invoice;

        return $invoice !== null
            ? "Pembayaran faktur {$invoice->nomor}"
            : 'Pembayaran pelanggan belum dicocokkan';
    }
}
