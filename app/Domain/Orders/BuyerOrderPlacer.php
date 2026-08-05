<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Uom\Unit;
use App\Models\CustomerUser;
use App\Models\Order;
use App\Models\Product;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A buyer placing an order from the portal.
 *
 * B2B buyers restock; they don't shop. The overwhelmingly common case is
 * "the same as last time, but three of these instead of two", which is why
 * repeat() exists as its own entry point rather than as a pre-filled form.
 *
 * What this does *not* do is price the order or hold any stock. It builds a
 * draft and submits it, and nothing else. Pricing, the credit check and the
 * stock reservation all happen at `confirmed`, which is a staff action — a
 * customer approving their own credit limit is not a workflow.
 */
class BuyerOrderPlacer
{
    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * Repeat a past order with edited quantities.
     *
     * @param  array<string, int>  $quantities  previous order line id => new ordered quantity.
     *                                          A quantity of 0 drops that line.
     */
    public function repeat(CustomerUser $buyer, Order $previous, array $quantities): Order
    {
        $this->assertOwnedBy($buyer, $previous);

        $lines = [];

        foreach ($previous->lines as $line) {
            $qty = (int) ($quantities[$line->id] ?? $line->ordered_qty);

            if ($qty < 1) {
                continue;
            }

            $lines[] = [
                'sku' => $line->sku,
                'ordered_unit' => $line->ordered_unit->value,
                'ordered_qty' => $qty,
            ];
        }

        return $this->place(
            buyer: $buyer,
            warehouseId: $previous->warehouse_id,
            lines: $lines,
            catatan: "Pesan ulang dari {$previous->nomor}.",
        );
    }

    /**
     * @param  list<array{sku: string, ordered_unit: string, ordered_qty: int}>  $lines
     */
    public function place(
        CustomerUser $buyer,
        int $warehouseId,
        array $lines,
        ?string $poPelanggan = null,
        ?string $catatan = null,
    ): Order {
        if ($lines === []) {
            throw new DomainException('Pesanan tanpa baris tidak bisa diajukan.');
        }

        $company = $buyer->company;

        if ($company === null || ! $company->isActive()) {
            // Belt and braces: canAccessPanel() already refuses a suspended
            // company at login, but a session outliving the suspension must not
            // be able to keep ordering on credit.
            throw new DomainException('Akun Anda sedang tidak aktif. Hubungi tim kami.');
        }

        $order = DB::transaction(function () use ($buyer, $company, $warehouseId, $lines, $poPelanggan, $catatan) {
            $order = Order::create([
                'nomor' => $this->numbers->nextOrderNumber(),
                'company_id' => $company->id,
                'warehouse_id' => $warehouseId,
                // No staff user placed this one. `created_by` stays null and
                // the buyer is recorded in its own column rather than being
                // dressed up as an employee.
                'placed_by_customer_user_id' => $buyer->id,
                'po_pelanggan' => $poPelanggan,
                'catatan' => $catatan,
            ]);

            $urutan = 1;

            foreach ($lines as $line) {
                $order->lines()->create($this->lineAttributes($line, $urutan++));
            }

            return $order;
        });

        return $this->orders->submitAsBuyer($order->refresh(), $buyer);
    }

    /**
     * qty_base is derived from the product, never taken from the request.
     *
     * The ledger counts base units; letting the browser tell us how many pieces
     * are in a carton would make a stock movement a matter of opinion.
     *
     * @param  array{sku: string, ordered_unit: string, ordered_qty: int}  $line
     * @return array<string, mixed>
     */
    private function lineAttributes(array $line, int $urutan): array
    {
        $product = Product::query()->where('kode', $line['sku'])->first();

        if ($product === null || ! $product->aktif) {
            throw new DomainException("Produk {$line['sku']} tidak tersedia.");
        }

        $unit = Unit::from($line['ordered_unit']);
        $qty = (int) $line['ordered_qty'];

        if ($qty < 1) {
            throw new DomainException("Jumlah untuk {$line['sku']} harus lebih dari nol.");
        }

        return [
            'sku' => $product->kode,
            'urutan' => $urutan,
            'ordered_unit' => $unit->value,
            'ordered_qty' => $qty,
            'qty_per_ctn_snapshot' => $product->qty_per_ctn,
            'satuan_dasar_snapshot' => $product->satuan_dasar,
            'qty_base' => $unit->toBaseQtyForProduct($qty, $product),
        ];
    }

    /**
     * A buyer may only repeat their own company's order.
     *
     * The resource query already scopes to the company, so reaching this with
     * someone else's order means a hand-crafted request — which is exactly when
     * you want the check to exist.
     */
    private function assertOwnedBy(CustomerUser $buyer, Order $order): void
    {
        if ($order->company_id !== $buyer->company_id) {
            throw new DomainException('Pesanan ini bukan milik akun Anda.');
        }
    }
}
