<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Money;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\Giro;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LandedCost;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\SupplierBill;
use App\Models\SupplierPaymentEntry;
use App\Models\User;
use DateTimeInterface;

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
 *   Piutang Usaha           = invoices, less payments, less credit notes
 *   Utang Usaha             = supplier bills, less payments, less purchase
 *                             returns of goods those bills covered
 *   Persediaan              = total value in product_costs. Transfers move
 *                             goods between warehouses and post nothing:
 *                             product_costs is keyed by SKU, so the total is
 *                             unchanged and there is nothing to say.
 *   Utang Belum Ditagih     = goods received and not yet billed, less returns
 *                             of goods nobody had billed for
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
     * Credit note posted: the customer owes less, and possibly the goods came
     * back.
     *
     *   Dr Penjualan          the sale being unwound, net of discount
     *   Dr PPN Keluaran       tax no longer collected on their behalf
     *     Cr Piutang Usaha    total
     *
     * Revenue is debited rather than a contra "Retur Penjualan" account being
     * credited. The same choice as booking sales net of discount, and the same
     * cost: returns are not a line on the profit and loss, only a document in
     * the register. Add Retur Penjualan if that ever needs to be visible —
     * which it will, the first time somebody asks how much came back.
     *
     * A retur posts a second entry for the goods; see shipmentReturned().
     */
    public function creditNoteIssued(CreditNote $note, ?User $actor = null): JournalEntry
    {
        $company = $note->company;

        $draft = JournalDraft::for(
            $note,
            JournalEntry::JENIS_NOTA_KREDIT,
            "Nota kredit {$note->nomor}",
            $note->tanggal,
        )
            ->debit(AccountCode::PENJUALAN, (int) $note->subtotal_rupiah, $note->alasan, company: $company)
            ->debit(AccountCode::PPN_KELUARAN, (int) $note->ppn_rupiah, 'PPN keluaran diretur')
            ->kredit(AccountCode::PIUTANG_USAHA, (int) $note->total_rupiah, $company?->nama, company: $company);

        $entry = $this->ledger->post($draft, $actor);

        $this->shipmentReturned($note, $actor);

        return $entry;
    }

    /**
     * Goods coming back off a credit note.
     *
     *   Dr Persediaan               at the cost they left at
     *     Cr Harga Pokok Penjualan
     *
     * The exact reverse of the shipment that sent them out, which is what
     * makes the margin on the part that was kept come out right. Valuing the
     * return at today's average instead would book a profit or a loss on goods
     * that merely came back.
     *
     * Returns null for a potongan, and for a retur of stock that was never
     * costed — there is no cost to put back, and an entry for nil would say
     * something happened.
     */
    public function shipmentReturned(CreditNote $note, ?User $actor = null): ?JournalEntry
    {
        $cost = (int) $note->hpp_rupiah;

        if ($cost === 0) {
            return null;
        }

        $draft = JournalDraft::for(
            $note,
            JournalEntry::JENIS_HPP_RETUR,
            "HPP retur {$note->nomor}",
            $note->tanggal,
        )
            ->debit(AccountCode::PERSEDIAAN, $cost)
            ->kredit(AccountCode::HARGA_POKOK_PENJUALAN, $cost, company: $note->company);

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
     * A stock count found the shelf out of step with the record.
     *
     *   shortfall:  Dr Selisih Persediaan / Cr Persediaan
     *   surplus:    Dr Persediaan         / Cr Selisih Persediaan
     *
     * Shrinkage is a cost of trading rather than an overhead — it moves with
     * how much stock is handled — so the variance account hangs under the HPP
     * header and lands above gross profit.
     *
     * The amount comes from the value the adjustment movements actually
     * carried, not from recomputing quantity times average. Recomputing is how
     * the ledger and the valuation end up a rupiah apart on an awkward
     * average, and Persediaan is a control account: a rupiah apart is a
     * reconciliation that fails every day until somebody chases it.
     *
     * Returns null when the count agreed with the record, which is the outcome
     * to hope for. An entry for nil would say something happened.
     */
    public function stockCountAdjusted(StockOpname $opname, ?User $actor = null): ?JournalEntry
    {
        $selisih = (int) $opname->selisih_rupiah;

        if ($selisih === 0) {
            return null;
        }

        $draft = JournalDraft::for(
            $opname,
            JournalEntry::JENIS_SELISIH_OPNAME,
            "Selisih stok opname {$opname->nomor}",
            $opname->tanggal,
        )
            // Signed, so a surplus and a shortfall are the same rule read in
            // two directions rather than two branches that can drift apart.
            ->debitSigned(AccountCode::PERSEDIAAN, $selisih)
            ->kreditSigned(AccountCode::SELISIH_PERSEDIAAN, $selisih, $opname->catatan);

        return $this->ledger->post($draft, $actor);
    }

    /**
     * A freight or duty charge landing on the goods it belongs to.
     *
     *   Dr Persediaan                    the share still on the shelf
     *   Dr Harga Pokok Penjualan         the share already sold
     *     Cr Biaya Belum Dialokasikan    the whole charge, leaving the queue
     *
     * The credit is the entire charge, always, which is what makes the
     * clearing account self-clearing: a charge either sits there in full or is
     * gone in full, and its balance is exactly the list of things nobody has
     * spread yet. There is no partial allocation and there should not be —
     * "half of this freight bill belongs somewhere I have not decided" is a
     * state that gets forgotten rather than finished.
     *
     * The HPP side is the part people get wrong. It is not a write-off. Those
     * goods really did cost that much to bring in, and they have already been
     * sold, so the cost belongs to the period we found out about it — the
     * alternative is restating a margin somebody has already reported. It is
     * booked to HPP rather than to an expense account because it *is* cost of
     * sales, just recognised late.
     *
     * Amounts come from what the movements actually carried, not recomputed,
     * for the same reason the opname rule does it: Persediaan is a control
     * account, and a rupiah of rounding drift is a reconciliation that fails
     * every day until somebody chases it.
     */
    public function landedCostAllocated(LandedCost $landedCost, User $actor): ?JournalEntry
    {
        $toInventory = (int) $landedCost->ke_persediaan_rupiah;
        $toCogs = (int) $landedCost->ke_hpp_rupiah;
        $total = $toInventory + $toCogs;

        if ($total === 0) {
            return null;
        }

        $supplier = $landedCost->supplierBillLine?->supplierBill?->supplier;

        $draft = JournalDraft::for(
            $landedCost,
            JournalEntry::JENIS_BIAYA_PEROLEHAN,
            "Biaya perolehan {$landedCost->nomor}",
            $landedCost->tanggal,
        )
            ->debit(AccountCode::PERSEDIAAN, $toInventory, 'Bagian barang yang masih ada')
            ->debit(AccountCode::HARGA_POKOK_PENJUALAN, $toCogs, 'Bagian barang yang sudah terjual')
            ->kredit(
                AccountCode::BIAYA_BELUM_DIALOKASIKAN,
                $total,
                $landedCost->catatan,
                supplier: $supplier,
            );

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
     *   Dr Biaya Belum Dialokasikan    freight and duty, waiting to be spread
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
     * clear is exact rather than apportioned. A goods line with no receipt
     * behind it — a bill that arrived before the delivery — clears nothing and
     * accrues the whole amount against Utang Belum Ditagih, which then goes
     * contra until the goods turn up. That is what the account is for.
     *
     * A **biaya** line is not that. Freight and duty are never going to be
     * matched by a delivery, so parking them in Utang Belum Ditagih would
     * leave a contra balance that nothing can ever clear — in the one account
     * whose usefulness depends on it being close to empty. They go to the
     * landed-cost clearing account instead, where a non-zero balance means
     * "somebody still has to spread this", which is true and actionable.
     */
    public function supplierBillPosted(SupplierBill $bill, User $actor): JournalEntry
    {
        $bill->loadMissing('lines.goodsReceiptLine', 'supplier');

        $supplier = $bill->supplier;
        $grni = 0;
        $variance = 0;
        $biaya = 0;

        foreach ($bill->lines as $line) {
            $billed = (int) $line->line_total_rupiah;
            $receiptLine = $line->goodsReceiptLine;

            if ($line->isBiaya()) {
                $biaya += $billed;

                continue;
            }

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
            )
            ->debit(
                AccountCode::BIAYA_BELUM_DIALOKASIKAN,
                $biaya,
                'Biaya perolehan, menunggu dialokasikan',
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
     * Goods going back to a supplier.
     *
     *   Dr Utang Usaha                 what they billed for the billed part,
     *                                  plus the PPN that came with it
     *   Dr Utang Belum Ditagih         what the receipt accrued for the part
     *                                  they had not billed
     *   Dr/Cr Selisih Harga Pembelian  the difference
     *     Cr Persediaan                what the goods were carried at when
     *                                  they left
     *     Cr PPN Masukan               input VAT no longer creditable
     *
     * Three things here are worth reading twice.
     *
     * **The two debits are not interchangeable.** Reducing Utang Usaha says a
     * named supplier owes us money against an invoice we hold. Reducing Utang
     * Belum Ditagih says a bill that was coming is now smaller. Sending a
     * return to the wrong one balances perfectly and leaves either a payable
     * standing for goods we no longer have, or an accrual gone contra in the
     * one account whose usefulness depends on being near empty. The split is
     * computed line by line — see PurchaseReturnPoster.
     *
     * **The credit to Persediaan is what the movements actually took out**,
     * which under moving-average costing need not equal what the supplier is
     * crediting: stock received since at a different price has moved the
     * average. Forcing the two to agree by recomputing one of them would put
     * the ledger's Persediaan out of step with product_costs, and that control
     * account is the only thing proving these rules are right at all.
     *
     * **So the variance line is not a plug.** It is the real gain or loss
     * between what we carried the goods at and what we get back for them,
     * which is the same thing Selisih Harga Pembelian already records when a
     * supplier bills a price the goods did not arrive at.
     *
     * Input VAT reverses to PPN Masukan where the bill carried a faktur pajak,
     * and to Beban Operasional where it never did — the exact mirror of how
     * the bill booked it, because reversing it to an account it never went to
     * would leave PPN Masukan understated and an expense standing forever.
     */
    public function purchaseReturnPosted(
        PurchaseReturn $return,
        User $actor,
        bool $inputVatCreditable = true,
    ): JournalEntry {
        $supplier = $return->supplier;
        $ppn = (int) $return->ppn_rupiah;

        $draft = JournalDraft::for(
            $return,
            JournalEntry::JENIS_RETUR_PEMBELIAN,
            "Retur pembelian {$return->nomor}",
            $return->tanggal,
        )
            ->debit(
                AccountCode::UTANG_USAHA,
                (int) $return->total_rupiah,
                $return->alasan,
                supplier: $supplier,
            )
            ->debit(
                AccountCode::UTANG_BELUM_DITAGIH,
                (int) $return->nilai_belum_ditagih_rupiah,
                'Bagian yang belum ditagih pemasok',
                supplier: $supplier,
            )
            ->debitSigned(
                AccountCode::SELISIH_HARGA_PEMBELIAN,
                (int) $return->selisih_rupiah,
                'Selisih nilai persediaan vs yang dikreditkan pemasok',
                supplier: $supplier,
            )
            ->kredit(AccountCode::PERSEDIAAN, (int) $return->nilai_persediaan_rupiah);

        if ($ppn > 0) {
            $draft->kredit(
                // Must be the account the bill debited, or the return unwinds
                // the charge somewhere else and both accounts drift for good.
                $inputVatCreditable ? AccountCode::PPN_MASUKAN : AccountCode::BEBAN_PPN_TIDAK_KREDIT,
                $ppn,
                $inputVatCreditable
                    ? "Nota retur {$return->nomor}"
                    : 'PPN tanpa faktur pajak — dibalik ke beban',
                supplier: $supplier,
            );
        }

        return $this->ledger->post($draft, $actor);
    }

    /**
     * A bilyet giro coming in or going out.
     *
     *   masuk:   Dr Piutang Giro   / Cr Piutang Usaha
     *   keluar:  Dr Utang Usaha    / Cr Utang Giro
     *
     * Nothing is earned, spent or settled here — a balance moves sideways into
     * an account that says what backs it. That is the whole point: a
     * receivable a customer has handed us signed paper for is a different
     * asset from one backed by an invoice and goodwill, and the neraca should
     * say which is which rather than averaging the two into one number.
     *
     * It is emphatically **not** a payment. No cash has moved, the invoice is
     * still open, and the customer's credit limit has not come back. See
     * OutstandingReceivables for why the control account and the credit check
     * read two different figures from here on.
     */
    /**
     * An expense, and its reversal, which is the same journal read backwards.
     *
     *     Dr  <akun beban>        the cost
     *       Cr  Kas | Bank        whichever pocket it left
     *
     * The simplest posting rule in the system, and the one whose absence did
     * the most damage: with no way to record an expense, the laba rugi showed
     * revenue and cost of sales against almost no overhead, which overstates
     * the profit that tax is calculated on.
     *
     * Sourced on the expense row rather than posted manually, so the ledger's
     * idempotency on (source, jenis) makes a double-click harmless. The
     * reversal is its own row and therefore its own entry, which is why it can
     * carry the same `jenis` without colliding.
     */
    public function expenseRecorded(Expense $expense, User $actor, bool $reverse = false): JournalEntry
    {
        $draft = JournalDraft::for(
            $expense,
            JournalEntry::JENIS_BEBAN,
            "{$expense->nomor} — {$expense->keterangan}",
            $expense->tanggal,
        );

        $beban = $expense->account->kode;
        $sumber = $expense->dibayar_dari->accountCode();
        $amount = (int) $expense->amount_rupiah;

        [$debit, $kredit] = $reverse ? [$sumber, $beban] : [$beban, $sumber];

        $draft
            ->debit($debit, $amount, $expense->keterangan, supplier: $expense->supplier)
            ->kredit($kredit, $amount, $expense->keterangan, supplier: $expense->supplier);

        return $this->ledger->post($draft, $actor);
    }

    public function giroIssued(Giro $giro, User $actor): JournalEntry
    {
        return $this->ledger->post($this->giroDraft(
            $giro,
            "Terima giro {$giro->nomor_warkat} ({$giro->bank_penerbit})",
            $giro->tanggal_terima,
            reverse: false,
        ), $actor);
    }

    /**
     * A giro leaving the register without money moving.
     *
     * The exact reverse of the entry above, and it covers three endings that
     * are the same journal three times: it bounced, it was handed back
     * uncashed, or it cleared — because clearing unwinds the instrument first
     * and *then* takes an ordinary payment through the ledger that already
     * knows how to settle an invoice.
     *
     * Routing clearing through the normal payment path rather than posting
     * Dr Bank / Cr Piutang Giro directly costs one extra journal entry and
     * buys the whole of it: invoice settlement, the ageing report, the
     * unmatched-payments queue, and the reversal machinery, none of it
     * duplicated and all of it already tested.
     */
    public function giroReleased(Giro $giro, User $actor, string $keterangan): JournalEntry
    {
        return $this->ledger->post($this->giroDraft(
            $giro,
            $keterangan,
            $giro->tanggal_selesai,
            reverse: true,
            jenis: JournalEntry::JENIS_GIRO_SELESAI,
        ), $actor);
    }

    /**
     * One rule, read in four directions.
     *
     * Incoming and outgoing giros are mirror images, and issuing and releasing
     * are mirror images again. Writing that as four branches would be four
     * places for a debit and a credit to get swapped, in a document whose
     * whole job is to keep two accounts honest against each other.
     */
    private function giroDraft(
        Giro $giro,
        string $keterangan,
        ?DateTimeInterface $tanggal,
        bool $reverse,
        string $jenis = JournalEntry::JENIS_GIRO,
    ): JournalDraft {
        $amount = (int) $giro->nilai_rupiah;
        $arah = $giro->arah;

        $draft = JournalDraft::for($giro, $jenis, $keterangan, $tanggal);

        $debitsGiro = $arah->debitsGiroAccountOnIssue() !== $reverse;

        [$debit, $kredit] = $debitsGiro
            ? [$arah->giroAccount(), $arah->ordinaryAccount()]
            : [$arah->ordinaryAccount(), $arah->giroAccount()];

        return $draft
            ->debit($debit, $amount, $giro->counterpartyName(),
                company: $giro->company, supplier: $giro->supplier)
            ->kredit($kredit, $amount, $giro->nomor_warkat,
                company: $giro->company, supplier: $giro->supplier);
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
            AccountCode::BEBAN_PPN_TIDAK_KREDIT,
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
