<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * What a delivery still allows to be sent back, and on what terms.
 *
 * Three facts have to come together before a return can be recorded honestly,
 * and no single table holds them:
 *
 *   - **What actually arrived**, and what it was valued at. The goods receipt
 *     line, which is the only record of the price we brought them in at.
 *   - **What has already gone back**, so two returns cannot each send back the
 *     same carton.
 *   - **What the supplier has billed**, because that decides whether sending
 *     something back reduces a debt or unwinds an accrual — a difference that
 *     lands in two different accounts and breaks a control account if it is
 *     guessed at.
 *
 * This class assembles them once, so the screen offering quantities and the
 * poster accepting them are reading the same arithmetic.
 */
class PurchaseReturnIssuer
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * Every line of a receipt, with what is still returnable on it.
     *
     * Lines that are fully returned stay in the list with a remaining quantity
     * of nil rather than disappearing. "Why can I not send this back" is a
     * question the screen should answer, and a missing row answers nothing.
     *
     * @return list<ReturnableLine>
     */
    public function returnable(GoodsReceipt $receipt): array
    {
        $lines = $receipt->lines()->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $ids = $lines->pluck('id')->all();

        $billed = $this->billedByReceiptLine($ids);
        $returned = $this->returnedByReceiptLine($ids);

        return $lines->map(function (GoodsReceiptLine $line) use ($billed, $returned) {
            $bill = $billed[$line->id] ?? ['qty' => 0, 'value' => 0, 'creditable' => false, 'bills' => []];
            $back = $returned[$line->id] ?? ['qty' => 0, 'billed_qty' => 0];

            return new ReturnableLine(
                receiptLine: $line,
                sku: $line->sku,
                deskripsi: $line->product?->description,
                receivedQty: (int) $line->qty_base,
                receivedValueRupiah: (int) $line->line_value_rupiah,
                returnedQty: (int) $back['qty'],
                billedQty: (int) $bill['qty'],
                billedValueRupiah: (int) $bill['value'],
                billIds: $bill['bills'],
                returnedBilledQty: (int) $back['billed_qty'],
                inputVatCreditable: (bool) $bill['creditable'],
            );
        })->all();
    }

    public function returnableFor(GoodsReceipt $receipt, GoodsReceiptLine $line): ?ReturnableLine
    {
        foreach ($this->returnable($receipt) as $returnable) {
            if ($returnable->receiptLine->is($line)) {
                return $returnable;
            }
        }

        return null;
    }

    /** Base units of a receipt that could still go back, across every line. */
    public function remainingQty(GoodsReceipt $receipt): int
    {
        $total = 0;

        foreach ($this->returnable($receipt) as $line) {
            $total += $line->remainingQty();
        }

        return $total;
    }

    /**
     * Start a draft against a posted receipt.
     *
     * Deliberately does no costing. A draft is somebody's intention, and every
     * figure is settled by PurchaseReturnPoster at the moment of posting —
     * pricing it here would mean two places deciding what a return is worth,
     * which is the bug this whole layer exists to avoid.
     */
    public function draft(
        GoodsReceipt $receipt,
        User $actor,
        string $alasan,
        ?DateTimeInterface $tanggal = null,
    ): PurchaseReturn {
        if (! $actor->role()->canReturnToSupplier()) {
            throw new DomainException('Anda tidak berhak membuat retur pembelian.');
        }

        /*
         * A draft receipt has put nothing on the shelf and nothing in the
         * books, so there is nothing to send back. Correct it instead — it is
         * still editable, which is the whole difference between the two states.
         */
        if (! $receipt->isPosted()) {
            throw new DomainException(
                "Penerimaan {$receipt->nomor} belum diposting, jadi belum ada barang untuk diretur."
            );
        }

        if (trim($alasan) === '') {
            throw new DomainException('Retur pembelian harus menyebutkan alasannya.');
        }

        if ($this->remainingQty($receipt) <= 0) {
            throw new DomainException(
                "Seluruh isi penerimaan {$receipt->nomor} sudah diretur."
            );
        }

        $tanggal ??= now();

        return PurchaseReturn::create([
            'nomor' => $this->numbers->nextPurchaseReturnNumber($tanggal),
            'supplier_id' => $receipt->supplier_id,
            'goods_receipt_id' => $receipt->id,
            'warehouse_id' => $receipt->warehouse_id,
            'tanggal' => $tanggal,
            'alasan' => $alasan,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Draft a return with every remaining line already on it.
     *
     * The common case by a distance: a delivery turns up wrong and the whole
     * lot goes back on the same truck. Starting from everything and deleting
     * what stays is fewer keystrokes than the reverse, and — more to the point
     * — it is the version where forgetting a line leaves you having returned
     * too much rather than having quietly kept goods you were not billed for.
     */
    public function draftEverything(
        GoodsReceipt $receipt,
        User $actor,
        string $alasan,
        ?DateTimeInterface $tanggal = null,
    ): PurchaseReturn {
        return DB::transaction(function () use ($receipt, $actor, $alasan, $tanggal) {
            $return = $this->draft($receipt, $actor, $alasan, $tanggal);

            $urutan = 0;

            foreach ($this->returnable($receipt) as $line) {
                if ($line->isFullyReturned()) {
                    continue;
                }

                PurchaseReturnLine::create([
                    'purchase_return_id' => $return->id,
                    'goods_receipt_line_id' => $line->receiptLine->id,
                    'sku' => $line->sku,
                    'urutan' => ++$urutan,
                    'deskripsi' => $line->deskripsi,
                    'ordered_unit' => $line->receiptLine->ordered_unit,
                    'ordered_qty' => $line->remainingQty(),
                    'qty_per_ctn_snapshot' => $line->receiptLine->qty_per_ctn_snapshot ?: 1,
                    'qty_base' => $line->remainingQty(),
                ]);
            }

            return $return->refresh();
        });
    }

    /**
     * What posted bills have billed against each receipt line.
     *
     * Void bills are excluded rather than netted: a voided bill was never owed,
     * so the goods it covered are unbilled again and a return of them unwinds
     * the accrual, which is what actually still stands.
     *
     * `creditable` says whether the bill carried a faktur pajak. Where a
     * receipt line has been billed more than once — a part delivery billed
     * twice — one of them having a faktur pajak is enough to make the reversal
     * creditable, because the alternative is to silently forfeit input VAT we
     * genuinely paid. It is also vanishingly rare, and the screen shows the
     * bill so anybody can see which.
     *
     * @param  list<int>  $receiptLineIds
     * @return array<int, array{qty: int, value: int, creditable: bool, bills: list<int>}>
     */
    private function billedByReceiptLine(array $receiptLineIds): array
    {
        return SupplierBillLine::query()
            ->join('supplier_bills', 'supplier_bill_lines.supplier_bill_id', '=', 'supplier_bills.id')
            ->whereIn('supplier_bill_lines.goods_receipt_line_id', $receiptLineIds)
            ->where('supplier_bill_lines.jenis', SupplierBillLine::JENIS_BARANG)
            ->whereNotNull('supplier_bills.posted_at')
            ->where('supplier_bills.status', '!=', SupplierBill::STATUS_VOID)
            ->groupBy('supplier_bill_lines.goods_receipt_line_id')
            ->selectRaw(
                'supplier_bill_lines.goods_receipt_line_id AS line_id, '
                .'SUM(supplier_bill_lines.qty_base) AS qty, '
                .'SUM(supplier_bill_lines.line_total_rupiah) AS value, '
                .'BOOL_OR(supplier_bills.nomor_faktur_pajak IS NOT NULL) AS creditable, '
                // Ordered so the id a return records does not depend on the
                // order the planner happened to aggregate in.
                .'ARRAY_AGG(DISTINCT supplier_bills.id ORDER BY supplier_bills.id) AS bills'
            )
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->line_id => [
                'qty' => (int) $row->qty,
                'value' => (int) $row->value,
                'creditable' => (bool) $row->creditable,
                'bills' => $this->parseIntArray($row->bills),
            ]])
            ->all();
    }

    /**
     * Postgres hands an `ARRAY_AGG` back as the literal `{3,7}`.
     *
     * @return list<int>
     */
    private function parseIntArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('intval', $value));
        }

        $trimmed = trim((string) $value, '{}');

        if ($trimmed === '') {
            return [];
        }

        return array_values(array_map('intval', explode(',', $trimmed)));
    }

    /**
     * What posted returns have already sent back against each receipt line.
     *
     * `billed_qty` is carried separately so the billed-first attribution does
     * not double-count: a second return against a half-billed line has to know
     * how much of the debt the first one already took off.
     *
     * @param  list<int>  $receiptLineIds
     * @return array<int, array{qty: int, billed_qty: int}>
     */
    private function returnedByReceiptLine(array $receiptLineIds): array
    {
        return PurchaseReturnLine::query()
            ->join('purchase_returns', 'purchase_return_lines.purchase_return_id', '=', 'purchase_returns.id')
            ->whereIn('purchase_return_lines.goods_receipt_line_id', $receiptLineIds)
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->groupBy('purchase_return_lines.goods_receipt_line_id')
            ->selectRaw(
                'purchase_return_lines.goods_receipt_line_id AS line_id, '
                .'SUM(purchase_return_lines.qty_base) AS qty, '
                .'SUM(purchase_return_lines.qty_ditagih) AS billed_qty'
            )
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->line_id => [
                'qty' => (int) $row->qty,
                'billed_qty' => (int) $row->billed_qty,
            ]])
            ->all();
    }
}
