<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Money;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Tax\TaxCalculator;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a credit note: stock comes back, the books reverse, the customer owes
 * less.
 *
 * One transaction, and every figure is decided here rather than on the draft.
 * A draft carries quantities and an intention; posting turns them into money,
 * using the invoice's own snapshots for price and the shipment's own frozen
 * value for cost.
 *
 * Posting is terminal, like every other money document here. A credit note
 * issued in error cannot be un-issued — see the note at the end of this class
 * about what that means and what it does not.
 */
class CreditNotePoster
{
    public function __construct(
        private readonly CreditNoteIssuer $issuer,
        private readonly TaxCalculator $tax,
        private readonly StockLedger $stock,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function post(CreditNote $note, User $actor): CreditNote
    {
        /*
         * Two different keys, by type. A retur moves goods, and the person
         * who says "the boxes are back on the shelf" is Inventori — never
         * the sales who filed the return, even when that sales is the Owner
         * wearing another hat: the drafter of a stock-moving note cannot be
         * its verifier. A pure price correction moves no goods, so it stays
         * with the seat that may issue it.
         */
        if ($note->jenis->movesStock()) {
            if (! $actor->role()->canVerifyReturns()) {
                throw new DomainException('Retur barang diverifikasi Inventori — merekalah yang memastikan barangnya benar-benar kembali.');
            }

            if ((int) $note->created_by === (int) $actor->getKey()) {
                throw new DomainException('Yang mengajukan retur tidak boleh memverifikasinya sendiri — dua kunci, dua orang.');
            }
        } elseif (! $actor->role()->canIssueCreditNote()) {
            throw new DomainException('Anda tidak berhak memposting nota kredit.');
        }

        return DB::transaction(function () use ($note, $actor) {
            /*
             * Re-read under a lock and re-check the status inside the
             * transaction. Two people hitting Posting on the same draft is not
             * hypothetical — it is what happens when the first click looks
             * slow — and posting twice would credit the customer twice and put
             * the returned goods on the shelf twice over.
             */
            $locked = CreditNote::query()->lockForUpdate()->findOrFail($note->id);

            if ($locked->isPosted()) {
                throw new DomainException("Nota kredit {$locked->nomor} sudah diposting.");
            }

            $invoice = $locked->invoice()->lockForUpdate()->firstOrFail();

            if ($invoice->status === Invoice::STATUS_VOID) {
                throw new DomainException("Faktur {$invoice->nomor} sudah dibatalkan.");
            }

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Nota kredit {$locked->nomor} tidak punya baris.");
            }

            $creditable = collect($this->issuer->creditable($invoice))
                ->keyBy(fn (CreditableLine $line) => $line->orderLine->id);

            $subtotal = 0;
            $dpp = 0;
            $ppn = 0;
            $hpp = 0;

            // Sorted by SKU for the same reason order confirmation is: two
            // documents touching the same two SKUs must take their stock locks
            // in the same sequence or they deadlock against each other.
            foreach ($lines->sortBy('sku')->values() as $line) {
                [$value, $cost] = $this->settleLine($locked, $line, $creditable);

                $breakdown = $this->tax->forLine($value);

                /*
                 * The display snapshots are filled in here too, not left to
                 * the printed document to derive. A nota kredit is read months
                 * later by somebody reconciling it against a faktur, and a
                 * description resolved live from the product table would show
                 * whatever that product is called today rather than what the
                 * customer was billed for.
                 */
                $source = $line->order_line_id === null
                    ? null
                    : $creditable->get($line->order_line_id);

                $line->forceFill([
                    'line_total_rupiah' => $value,
                    'dpp_rupiah' => $breakdown->dpp,
                    'ppn_rupiah' => $breakdown->ppn,
                    'line_cost_rupiah' => $cost,
                    'unit_cost_rupiah' => $line->qty_base > 0
                        ? Money::mulDiv($cost, 1, (int) $line->qty_base)
                        : 0,
                    'unit_price_rupiah' => $source?->unitPriceRupiah() ?? 0,
                    'deskripsi' => $line->deskripsi ?? $source?->deskripsi,
                    'qty_per_ctn_snapshot' => $source?->orderLine->qty_per_ctn_snapshot
                        ?? $line->qty_per_ctn_snapshot,
                ])->save();

                $subtotal += $breakdown->hargaJual;
                $dpp += $breakdown->dpp;
                $ppn += $breakdown->ppn;
                $hpp += $cost;

                if ($locked->jenis->movesStock() && $line->qty_base > 0) {
                    /*
                     * Back on the shelf at the cost it left at. Passing the
                     * value explicitly is what stops the return being valued
                     * at today's moving average — which would book a profit or
                     * a loss on goods that merely came back.
                     */
                    $this->stock->record(
                        sku: $line->sku,
                        warehouseId: $locked->warehouse_id,
                        qtySigned: (int) $line->qty_base,
                        reason: MovementReason::Retur,
                        referenceType: CreditNote::class,
                        referenceId: (string) $locked->id,
                        actor: $actor,
                        valueRupiah: $cost,
                    );
                }
            }

            $total = $subtotal + $ppn;

            $this->assertWithinInvoice($locked, $invoice, $total);

            $locked->forceFill([
                'subtotal_rupiah' => $subtotal,
                'dpp_rupiah' => $dpp,
                'ppn_rupiah' => $ppn,
                'total_rupiah' => $total,
                'hpp_rupiah' => $hpp,
                'kode_transaksi' => $this->tax->kodeTransaksi(),
                'status' => CreditNote::STATUS_POSTED,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            /*
             * Dr Penjualan + PPN Keluaran / Cr Piutang Usaha, and for a retur
             * a second entry putting the cost back: Dr Persediaan / Cr HPP.
             */
            $this->poster->creditNoteIssued($locked->refresh(), $actor);

            /*
             * A credit note can settle an invoice by itself — the goods went
             * back and there is nothing left to pay. Status follows the
             * arithmetic rather than whoever happened to act last.
             */
            $this->resettleInvoice($invoice);

            $this->audit->log(
                action: 'credit_note_posted',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'invoice_id' => $invoice->id,
                    'jenis' => $locked->jenis->value,
                    'subtotal_rupiah' => $subtotal,
                    'ppn_rupiah' => $ppn,
                    'total_rupiah' => $total,
                    'hpp_rupiah' => $hpp,
                ],
                actor: $actor,
                alasan: $locked->alasan,
            );

            return $note->refresh();
        });
    }

    /**
     * What one line is worth and what it cost, with every limit enforced.
     *
     * @param  Collection<int, CreditableLine>  $creditable
     * @return array{0: int, 1: int} value, cost
     */
    private function settleLine(
        CreditNote $note,
        CreditNoteLine $line,
        Collection $creditable,
    ): array {
        /*
         * A potongan with no order line behind it — a settlement on the
         * invoice as a whole. Nothing shipped, nothing comes back, and the
         * amount is whatever was agreed, capped against the invoice below.
         */
        if ($line->order_line_id === null) {
            if ($note->jenis->movesStock()) {
                throw new DomainException(
                    "Baris {$line->sku} pada retur barang harus menunjuk baris order yang dikembalikan."
                );
            }

            if ((int) $line->line_total_rupiah <= 0) {
                throw new DomainException("Baris {$line->sku} tidak punya nilai.");
            }

            return [(int) $line->line_total_rupiah, 0];
        }

        $source = $creditable->get($line->order_line_id);

        if ($source === null) {
            throw new DomainException(
                "Baris {$line->sku} tidak termasuk dalam faktur yang dikreditkan."
            );
        }

        if (! $note->jenis->movesStock()) {
            // A price correction on a named line: no goods move, so quantity
            // is not the limit — the line's own remaining value is.
            $value = (int) $line->line_total_rupiah;

            if ($value <= 0) {
                throw new DomainException("Baris {$line->sku} tidak punya nilai.");
            }

            if ($value > $source->remainingValueRupiah()) {
                throw new DomainException(sprintf(
                    'Potongan untuk %s melebihi sisa nilai baris: diminta %s, tersisa %s.',
                    $line->sku,
                    number_format($value, 0, ',', '.'),
                    number_format($source->remainingValueRupiah(), 0, ',', '.'),
                ));
            }

            return [$value, 0];
        }

        $qty = (int) $line->qty_base;

        if ($qty <= 0) {
            throw new DomainException("Baris {$line->sku} tidak punya jumlah retur.");
        }

        if ($qty > $source->remainingQty()) {
            throw new DomainException(sprintf(
                'Retur %s melebihi yang pernah dikirim: diminta %d, tersisa %d dari %d yang dikirim.',
                $line->sku,
                $qty,
                $source->remainingQty(),
                $source->shippedQty,
            ));
        }

        return [$source->valueFor($qty), $source->costFor($qty)];
    }

    /**
     * Nothing may be credited beyond what was invoiced.
     *
     * Crediting more than the invoice is not a return — it is a payment out,
     * and there is a ledger for those. Checked against everything already
     * posted, so three small notes cannot do what one large one is refused.
     */
    private function assertWithinInvoice(CreditNote $note, Invoice $invoice, int $total): void
    {
        $alreadyCredited = (int) CreditNote::query()
            ->where('invoice_id', $invoice->id)
            ->where('id', '!=', $note->id)
            ->posted()
            ->sum('total_rupiah');

        $room = (int) $invoice->total_rupiah - $alreadyCredited;

        if ($total > $room) {
            throw new DomainException(sprintf(
                'Nota kredit melebihi nilai faktur %s: diminta %s, tersisa %s.',
                $invoice->nomor,
                number_format($total, 0, ',', '.'),
                number_format(max(0, $room), 0, ',', '.'),
            ));
        }
    }

    /**
     * An invoice is paid when nothing is left owing, whether that happened by
     * payment or by credit.
     */
    private function resettleInvoice(Invoice $invoice): void
    {
        $invoice = $invoice->fresh();

        if ($invoice->status === Invoice::STATUS_OPEN && $invoice->amountOutstanding() <= 0) {
            $invoice->forceFill(['status' => Invoice::STATUS_PAID])->save();
        }
    }
}
