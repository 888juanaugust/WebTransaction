<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Access\Role;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Money;
use App\Domain\Stock\MovementReason;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockMovement;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Collection;

/**
 * What an invoice still allows to be credited, and at what price and cost.
 *
 * Three facts have to come together before a return can be recorded honestly,
 * and none of them lives on the invoice:
 *
 *   - **What actually shipped.** You cannot return what never left, and the
 *     invoice is raised before the goods go out, so the invoice is no evidence
 *     of delivery. The stock ledger is.
 *   - **What it was invoiced at**, net of whatever discount was agreed. The
 *     order line snapshot holds that, and the live price list does not.
 *   - **What it cost us**, frozen at the moment it left. A return valued at
 *     today's moving average invents margin out of a price change.
 *
 * This class puts them together once, so the form offering quantities and the
 * poster accepting them are reading the same numbers.
 */
class CreditNoteIssuer
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * Every line of the invoice's order that could still be credited.
     *
     * Lines that never shipped are included with a remaining quantity of nil
     * rather than dropped: "why can I not return this" is a question the
     * screen should answer, and a missing row answers nothing.
     *
     * @return list<CreditableLine>
     */
    public function creditable(Invoice $invoice): array
    {
        $order = $invoice->order;

        if ($order === null) {
            return [];
        }

        $lines = $order->lines()->orderBy('urutan')->orderBy('id')->get();
        $shipped = $this->shippedBySku($order);
        $credited = $this->creditedByOrderLine($invoice);

        return $lines->map(function (OrderLine $line) use ($shipped, $credited) {
            $ship = $shipped[$line->sku] ?? ['qty' => 0, 'value' => 0];
            $done = $credited[$line->id] ?? ['qty' => 0, 'value' => 0];

            /*
             * Shipment quantities are per SKU, not per order line: the stock
             * ledger records what left, and it has no idea which line of the
             * order asked for it. An order with the same SKU on two lines is
             * unusual but not impossible, so the shipped quantity is capped at
             * what this line ordered — otherwise both lines would each claim
             * the whole shipment.
             */
            $shippedQty = min((int) $ship['qty'], (int) $line->qty_base);
            $shippedCost = $ship['qty'] > 0
                ? Money::mulDiv((int) $ship['value'], $shippedQty, (int) $ship['qty'])
                : 0;

            return new CreditableLine(
                orderLine: $line,
                sku: $line->sku,
                deskripsi: $line->description_snapshot,
                shippedQty: $shippedQty,
                creditedQty: (int) $done['qty'],
                creditedValue: (int) $done['value'],
                unitCostRupiah: $shippedQty > 0
                    ? Money::mulDiv($shippedCost, 1, $shippedQty)
                    : 0,
                shippedCostRupiah: $shippedCost,
            );
        })->all();
    }

    public function creditableFor(Invoice $invoice, OrderLine $line): ?CreditableLine
    {
        foreach ($this->creditable($invoice) as $creditable) {
            if ($creditable->orderLine->is($line)) {
                return $creditable;
            }
        }

        return null;
    }

    /** Total already credited against an invoice on posted notes. */
    public function creditedTotal(Invoice $invoice): int
    {
        return (int) CreditNote::query()
            ->where('invoice_id', $invoice->id)
            ->posted()
            ->sum('total_rupiah');
    }

    /**
     * How much of an invoice is still creditable, in rupiah including PPN.
     *
     * Nothing may be credited beyond what was billed. Crediting more than the
     * invoice is not a return, it is a payment — and this system has a ledger
     * for those.
     */
    public function creditableTotal(Invoice $invoice): int
    {
        return max(0, (int) $invoice->total_rupiah - $this->creditedTotal($invoice));
    }

    /**
     * Start a draft against an invoice.
     *
     * Deliberately does no pricing: a draft is somebody's intention, and every
     * figure is settled by CreditNotePoster at the moment of posting. Pricing
     * a draft would mean two places that decide what a return is worth.
     */
    public function draft(
        Invoice $invoice,
        CreditNoteType $jenis,
        User $actor,
        string $alasan,
        ?DateTimeInterface $tanggal = null,
        ?int $warehouseId = null,
    ): CreditNote {
        if (! $actor->role()->canIssueCreditNote()) {
            throw new DomainException('Anda tidak berhak menerbitkan nota kredit.');
        }

        /*
         * A sales files returns for the stores they hold, not for a
         * colleague's — the same seat rule as their pelunasan claims. The
         * customer cannot file one at all: retur comes in through the sales
         * who visits, and Inventori's posting is the verification.
         */
        if ($actor->role() === Role::Sales
            && (int) $invoice->company->sales_user_id !== (int) $actor->getKey()) {
            throw new DomainException("Pelanggan {$invoice->company->nama} bukan tanggung jawab Anda — returnya diajukan sales yang memegang toko itu.");
        }

        if ($invoice->status === Invoice::STATUS_VOID) {
            throw new DomainException("Faktur {$invoice->nomor} sudah dibatalkan.");
        }

        if (trim($alasan) === '') {
            throw new DomainException('Nota kredit harus menyebutkan alasannya.');
        }

        if ($jenis->movesStock() && $warehouseId === null) {
            throw new DomainException('Retur barang harus menyebutkan gudang tujuan.');
        }

        $tanggal ??= now();

        return CreditNote::create([
            'nomor' => $this->numbers->nextCreditNoteNumber($tanggal),
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'jenis' => $jenis,
            'tanggal' => $tanggal,
            'alasan' => $alasan,
            'warehouse_id' => $jenis->movesStock() ? $warehouseId : null,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Base units shipped per SKU for an order, with the cost frozen on them.
     *
     * @return array<string, array{qty: int, value: int}>
     */
    private function shippedBySku(Order $order): array
    {
        return StockMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', (string) $order->id)
            ->where('reason', MovementReason::Pengiriman->value)
            ->get()
            ->groupBy('sku')
            ->map(fn (Collection $moves) => [
                // Outbound movements are negative on both counts; what left is
                // the magnitude. An unvalued movement contributes no cost,
                // which is the honest answer for stock that was never costed.
                'qty' => (int) $moves->sum(fn ($m) => -(int) $m->qty_signed),
                'value' => (int) $moves->sum(fn ($m) => $m->value_rupiah === null ? 0 : -(int) $m->value_rupiah),
            ])
            ->all();
    }

    /**
     * What posted notes have already taken off each order line.
     *
     * @return array<int, array{qty: int, value: int}>
     */
    private function creditedByOrderLine(Invoice $invoice): array
    {
        return CreditNoteLine::query()
            ->join('credit_notes', 'credit_note_lines.credit_note_id', '=', 'credit_notes.id')
            ->where('credit_notes.invoice_id', $invoice->id)
            ->where('credit_notes.status', CreditNote::STATUS_POSTED)
            ->whereNotNull('credit_note_lines.order_line_id')
            ->groupBy('credit_note_lines.order_line_id')
            ->selectRaw(
                'credit_note_lines.order_line_id AS line_id, '
                .'SUM(credit_note_lines.qty_base) AS qty, '
                .'SUM(credit_note_lines.line_total_rupiah) AS value'
            )
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->line_id => ['qty' => (int) $row->qty, 'value' => (int) $row->value],
            ])
            ->all();
    }
}
