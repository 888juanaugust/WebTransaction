<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What is running out, and how much to order.
 *
 * The opposite question to the stock ageing report, which asks what is sitting
 * there. This one is the question a spare parts business gets wrong in the
 * expensive direction: a bengkel that cannot get a part today buys it from
 * somebody else today, and often keeps buying it from them.
 *
 * ## Measured, not assumed
 *
 * Both inputs come from what actually happened rather than from a number
 * somebody typed once and never revisited:
 *
 *   laju harian     base units shipped over the last year, divided by 365
 *   lead time       days from sending a purchase order to the goods arriving,
 *                   per supplier, from real receipts
 *
 * A year for the sales rate so a seasonal part is not condemned on one quiet
 * quarter — the same window and the same reasoning as the ageing report.
 *
 *   titik pesan ulang = laju harian × (lead time + hari aman)
 *
 * Below that line, the shelf runs out before a replacement can arrive.
 *
 * ## Three things that are easy to get wrong, and all cost money
 *
 * **Stock already on order counts.** Not counting it is how a reorder list
 * doubles the warehouse: the part is below the line on Monday, somebody orders,
 * it is still below the line on Tuesday because nothing has arrived, and
 * somebody orders again. Draft purchase orders count too — a draft raised from
 * this very screen is a decision already made, and ignoring it means the button
 * produces a second order every time it is pressed.
 *
 * **Reserved stock is not stock.** Goods fenced for a confirmed order are going
 * to leave. Counting them as cover is how a part is "in stock" right up to the
 * morning somebody goes to pick it.
 *
 * **The order is in whole cartons.** Suppliers sell by the dus, so a suggestion
 * of 37 pieces of a part that comes 12 to a carton is a suggestion nobody can
 * place, and whoever places it rounds — in whichever direction they feel like.
 * Rounding here, upward, means the number on the screen is the number that gets
 * ordered.
 *
 * ## What is deliberately left out
 *
 * A part that has **never sold** gets no reorder point rather than one of zero.
 * There is no rate to compute a point from, and "we have never sold this" is a
 * buying question rather than a restocking one — it belongs on the ageing
 * report, which is where it already is.
 */
class ReorderAdvisor
{
    /**
     * How far back the sales rate is measured.
     *
     * A year, so a seasonal part is not condemned on a quiet quarter.
     */
    public const RATE_WINDOW_DAYS = 365;

    /**
     * Cover to hold beyond the lead time.
     *
     * The lead time is an average, and half of all deliveries are slower than
     * average. Two weeks of buffer is what stops a part going to zero every
     * time a shipment is a few days late.
     */
    public const SAFETY_DAYS = 14;

    /**
     * How long an order is meant to last once it arrives.
     *
     * Six weeks. Ordering to the reorder point exactly would put the part back
     * on this list the following week; ordering a year's worth ties up cash in
     * a warehouse. This is the number to argue about with whoever buys.
     */
    public const TARGET_COVER_DAYS = 42;

    /**
     * Used when a supplier has never delivered against a purchase order.
     *
     * Three weeks, which is a plausible domestic lead time, and every row says
     * out loud whether its figure was measured or assumed — an assumption
     * presented as a measurement is worse than no figure at all.
     */
    public const DEFAULT_LEAD_TIME_DAYS = 21;

    /**
     * Everything at or below its reorder point, worst first.
     *
     * @return list<ReorderSuggestion>
     */
    public function suggestions(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->endOfDay();
        $since = $asOf->copy()->subDays(self::RATE_WINDOW_DAYS);

        $sold = $this->soldSince($since, $asOf);
        $stock = $this->stockPositions();
        $onOrder = $this->onOrder();
        ['skus' => $lastSupplier, 'names' => $supplierNames] = $this->lastSupplierPerSku();
        $leadTimes = $this->measuredLeadTimes();

        $rows = [];

        foreach ($this->products() as $sku => $product) {
            if ($product['jangan_pesan_ulang']) {
                continue;
            }

            $lajuHarian = (int) ($sold[$sku] ?? 0) / self::RATE_WINDOW_DAYS;
            $supplierId = $lastSupplier[$sku] ?? null;

            [$leadTime, $terukur] = $this->leadTimeFor($supplierId, $leadTimes);

            $manual = $product['titik_pesan_ulang_manual'];
            $titik = $manual ?? (int) ceil($lajuHarian * ($leadTime + self::SAFETY_DAYS));

            /*
             * Nothing to say about a part with no rate and no manual figure.
             * A derived point of zero would put every never-sold SKU on this
             * list at "0 of 0", which is noise that hides the real rows.
             */
            if ($manual === null && $lajuHarian <= 0.0) {
                continue;
            }

            $onHand = $stock[$sku]['on_hand'] ?? 0;
            $reserved = $stock[$sku]['reserved'] ?? 0;
            $ordered = $onOrder[$sku] ?? 0;
            $posisi = $onHand - $reserved + $ordered;

            if ($posisi > $titik) {
                continue;
            }

            $rows[] = $this->suggest(
                $sku, $product, $onHand, $reserved, $ordered, $posisi,
                $titik, $manual !== null, $lajuHarian, $leadTime, $terukur,
                $supplierId, $supplierNames[$supplierId] ?? null,
            );
        }

        return $this->worstFirst($rows);
    }

    /**
     * Just the ones for a single supplier, in the order they would be bought.
     *
     * @return list<ReorderSuggestion>
     */
    public function forSupplier(int $supplierId, ?Carbon $asOf = null): array
    {
        return array_values(array_filter(
            $this->suggestions($asOf),
            fn (ReorderSuggestion $s) => $s->supplierId === $supplierId,
        ));
    }

    private function suggest(
        string $sku,
        array $product,
        int $onHand,
        int $reserved,
        int $ordered,
        int $posisi,
        int $titik,
        bool $manual,
        float $lajuHarian,
        int $leadTime,
        bool $terukur,
        ?int $supplierId,
        ?string $supplierNama,
    ): ReorderSuggestion {
        /*
         * Order up to a target rather than up to the reorder point. Filling to
         * the point exactly puts the part back on this list next week.
         *
         * With a manual point and no measured rate there is no target to
         * compute, so the target is the point itself — the person who typed it
         * said what they wanted on the shelf.
         */
        $target = $lajuHarian > 0.0
            ? (int) ceil($lajuHarian * ($leadTime + self::SAFETY_DAYS + self::TARGET_COVER_DAYS))
            : $titik;

        $kurang = max(0, $target - $posisi);

        // Whole cartons, rounded up. qty_per_ctn is 1 for parts sold loose,
        // which makes this a no-op rather than a special case.
        $perCtn = max(1, $product['qty_per_ctn']);
        $ctn = (int) ceil($kurang / $perCtn);

        return new ReorderSuggestion(
            sku: $sku,
            nama: $product['nama'],
            merk: $product['merk'],
            qtyPerCtn: $perCtn,
            satuanDasar: $product['satuan_dasar'],
            onHand: $onHand,
            reserved: $reserved,
            onOrder: $ordered,
            posisi: $posisi,
            titikPesanUlang: $titik,
            titikManual: $manual,
            lajuHarian: round($lajuHarian, 3),
            leadTimeHari: $leadTime,
            leadTimeTerukur: $terukur,
            saranQtyCtn: $ctn,
            saranQtyBase: $ctn * $perCtn,
            supplierId: $supplierId,
            supplierNama: $supplierNama,
        );
    }

    /**
     * Out of stock first, then whatever nothing is covering, then by how far
     * under the line it is.
     *
     * The ordering is the recommendation. A part that is out with nothing on
     * the way is somebody turning a customer away this morning; a part under
     * its point with a purchase order already covering it is being handled.
     *
     * @param  list<ReorderSuggestion>  $rows
     * @return list<ReorderSuggestion>
     */
    private function worstFirst(array $rows): array
    {
        usort($rows, function (ReorderSuggestion $a, ReorderSuggestion $b) {
            if ($a->isHabis() !== $b->isHabis()) {
                return $a->isHabis() ? -1 : 1;
            }

            if ($a->isUncovered() !== $b->isUncovered()) {
                return $a->isUncovered() ? -1 : 1;
            }

            // Proportionally short, not absolutely: being 40 under a point of
            // 50 is a crisis, being 40 under a point of 4000 is a rounding
            // error, and sorting on the raw gap puts the fast movers on top
            // every time regardless of how covered they are.
            return ($a->posisi - $a->titikPesanUlang) * max(1, $b->titikPesanUlang)
                <=> ($b->posisi - $b->titikPesanUlang) * max(1, $a->titikPesanUlang);
        });

        return $rows;
    }

    /**
     * The lead time to use, and whether anybody actually measured it.
     *
     * @param  array<int, int>  $measured
     * @return array{0: int, 1: bool}
     */
    private function leadTimeFor(?int $supplierId, array $measured): array
    {
        if ($supplierId !== null && isset($measured[$supplierId])) {
            return [$measured[$supplierId], true];
        }

        return [self::DEFAULT_LEAD_TIME_DAYS, false];
    }

    /**
     * Days from sending a purchase order to the goods arriving, per supplier.
     *
     * Averaged over receipts that actually came from a purchase order — a
     * receipt with no PO behind it is stock that simply turned up, and it says
     * nothing about how long the supplier takes.
     *
     * Measured from `sent_at` rather than `tanggal_po`, because a PO that sat
     * in draft for a week is our delay and not theirs.
     *
     * **Both sides cast to a date first.** `sent_at` is a timestamp and
     * `tanggal_terima` is a date, so subtracting them directly gives an
     * interval of six days and fifteen hours for an order sent Thursday
     * morning and received the following Thursday — which truncates to six.
     * The error is always downward, so it shortens every lead time, lowers
     * every reorder point, and biases the whole system toward stocking out.
     * That is the one direction this must not be wrong in.
     *
     * @return array<int, int>
     */
    private function measuredLeadTimes(): array
    {
        return DB::table('goods_receipts')
            ->whereBoundRegion('goods_receipts')
            ->join('purchase_orders', 'goods_receipts.purchase_order_id', '=', 'purchase_orders.id')
            ->whereNotNull('purchase_orders.sent_at')
            ->whereNotNull('goods_receipts.posted_at')
            ->groupBy('purchase_orders.supplier_id')
            ->selectRaw(
                'purchase_orders.supplier_id AS supplier_id, '
                .'AVG(GREATEST(0, goods_receipts.tanggal_terima::date '
                .'- purchase_orders.sent_at::date)) AS hari'
            )
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->supplier_id => max(1, (int) round((float) $row->hari)),
            ])
            ->all();
    }

    /**
     * Base units on purchase orders that have not arrived.
     *
     * Drafts included. A draft raised from the reorder screen is a decision
     * somebody already made, and leaving it out means pressing the button twice
     * orders the same shortage twice.
     *
     * @return array<string, int>
     */
    private function onOrder(): array
    {
        return DB::table('purchase_order_lines')
            ->whereBoundRegion('purchase_orders')
            ->join('purchase_orders', 'purchase_order_lines.purchase_order_id', '=', 'purchase_orders.id')
            ->whereIn('purchase_orders.status', [
                PurchaseOrderStatus::Draft->value,
                PurchaseOrderStatus::Dikirim->value,
            ])
            ->groupBy('purchase_order_lines.sku')
            ->selectRaw(
                'purchase_order_lines.sku AS sku, '
                .'SUM(GREATEST(0, purchase_order_lines.qty_base '
                .'- purchase_order_lines.qty_base_received)) AS qty'
            )
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->sku => (int) $row->qty])
            ->all();
    }

    /**
     * On hand and reserved, summed across warehouses.
     *
     * Across all of them, because a reorder point is a buying decision and
     * buying is done for the business rather than for a shelf. Which warehouse
     * it goes to is a question for the purchase order.
     *
     * @return array<string, array{on_hand: int, reserved: int}>
     */
    private function stockPositions(): array
    {
        return DB::table('stock_levels')
            ->whereBoundRegion('stock_levels')
            ->groupBy('sku')
            ->selectRaw('sku, SUM(qty_on_hand) AS on_hand, SUM(qty_reserved) AS reserved')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->sku => [
                'on_hand' => (int) $row->on_hand,
                'reserved' => (int) $row->reserved,
            ]])
            ->all();
    }

    /**
     * Who we last bought each part from.
     *
     * Products carry no supplier, and giving them one would be a second place
     * for the answer to live. The last receipt is a fact rather than a setting,
     * and it is the answer to the question actually being asked — "who do I
     * ring about this part".
     *
     * Names come back alongside the ids so the caller needs no second query.
     * Two keys rather than one merged map: a SKU is a free-text code, and a
     * map holding both would break the day somebody codes a part 'nama'.
     *
     * @return array{skus: array<string, int>, names: array<int, string>}
     */
    private function lastSupplierPerSku(): array
    {
        $rows = DB::table('goods_receipt_lines')
            ->whereBoundRegion('goods_receipts')
            ->join('goods_receipts', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
            ->join('suppliers', 'goods_receipts.supplier_id', '=', 'suppliers.id')
            ->whereNotNull('goods_receipts.posted_at')
            ->orderBy('goods_receipt_lines.sku')
            ->orderByDesc('goods_receipts.tanggal_terima')
            ->orderByDesc('goods_receipts.id')
            ->select([
                'goods_receipt_lines.sku',
                'goods_receipts.supplier_id',
                'suppliers.nama',
            ])
            ->get();

        $bySku = [];
        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row->supplier_id] = (string) $row->nama;

            // Ordered newest-first per SKU, so the first one wins.
            $bySku[(string) $row->sku] ??= (int) $row->supplier_id;
        }

        return ['skus' => $bySku, 'names' => $names];
    }

    /**
     * @return array<string, array{nama: string, merk: string, qty_per_ctn: int,
     *     satuan_dasar: string, titik_pesan_ulang_manual: ?int, jangan_pesan_ulang: bool}>
     */
    private function products(): array
    {
        return DB::table('products')
            ->where('aktif', true)
            ->get([
                'kode', 'description', 'merk', 'qty_per_ctn', 'satuan_dasar',
                'titik_pesan_ulang_manual', 'jangan_pesan_ulang',
            ])
            ->mapWithKeys(fn ($row) => [(string) $row->kode => [
                'nama' => (string) ($row->description ?? $row->kode),
                'merk' => (string) ($row->merk ?? ''),
                'qty_per_ctn' => (int) $row->qty_per_ctn,
                'satuan_dasar' => (string) $row->satuan_dasar,
                'titik_pesan_ulang_manual' => $row->titik_pesan_ulang_manual === null
                    ? null
                    : (int) $row->titik_pesan_ulang_manual,
                'jangan_pesan_ulang' => (bool) $row->jangan_pesan_ulang,
            ]])
            ->all();
    }

    /**
     * Base units shipped in the window.
     *
     * Shipments only — the same rule the ageing report follows. A transfer
     * between our own warehouses is not demand, and counting it would have the
     * business reordering because somebody moved a pallet.
     *
     * @return array<string, int>
     */
    private function soldSince(Carbon $since, Carbon $until): array
    {
        return DB::table('stock_movements')
            ->whereBoundRegion('stock_movements')
            ->where('reason', MovementReason::Pengiriman->value)
            ->whereBetween('created_at', [$since, $until])
            ->groupBy('sku')
            ->selectRaw('sku, -SUM(qty_signed) AS qty')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->sku => (int) $row->qty])
            ->all();
    }

    /** Every warehouse a suggested order could be sent to. */
    public function warehouses(): array
    {
        return Warehouse::query()->where('aktif', true)->orderBy('nama')->pluck('nama', 'id')->all();
    }
}
