<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use App\Domain\Credit\DebtAging;
use App\Domain\Orders\BuyerOrderPlacer;
use App\Domain\Uom\Unit;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerUser;
use App\Models\Order;
use App\Models\Product;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The buyer's basket.
 *
 * Reorder covers the common case — the same 15–20 SKUs again — and this covers
 * the rest: browsing the catalogue and building an order line by line.
 *
 * Nothing here decides a price. The cart holds what was asked for; what it
 * costs is answered by resolvePrice() when a screen needs to show a figure, and
 * only becomes binding when the order line snapshots it at `confirmed`.
 * CartTotals exists to make that indicative number, and it is never written
 * back.
 */
class CartService
{
    public function __construct(
        private readonly BuyerOrderPlacer $placer,
        private readonly DebtAging $aging,
    ) {}

    /**
     * The buyer's cart, created on first use.
     *
     * firstOrCreate against a UNIQUE customer_user_id, so two tabs adding an
     * item at the same moment cannot end up with two baskets.
     */
    public function forBuyer(CustomerUser $buyer): Cart
    {
        $companyId = $buyer->company_id;

        if ($companyId === null) {
            throw new DomainException('Akun Anda belum terhubung ke perusahaan.');
        }

        return Cart::firstOrCreate(
            ['customer_user_id' => $buyer->id],
            [
                'company_id' => $companyId,
                'warehouse_id' => Warehouse::query()->where('aktif', true)->value('id'),
            ],
        );
    }

    /**
     * Add a SKU, or increase it if it is already in the basket in this unit.
     *
     * Adding the same thing twice is one line with a bigger number. Two lines
     * saying "10 PCS" would read as a bug on the buyer's own screen.
     */
    public function add(CustomerUser $buyer, string $sku, Unit $unit, int $qty): CartItem
    {
        $product = $this->orderableProduct($sku);

        if ($qty < 1) {
            throw new DomainException('Jumlah harus lebih dari nol.');
        }

        // Validate the conversion now rather than at checkout, so an
        // impossible line never gets into the basket in the first place.
        $unit->toBaseQtyForProduct($qty, $product);

        $cart = $this->forBuyer($buyer);

        return DB::transaction(function () use ($cart, $product, $unit, $qty) {
            $item = $cart->items()
                ->where('sku', $product->kode)
                ->where('ordered_unit', $unit->value)
                ->lockForUpdate()
                ->first();

            if ($item !== null) {
                $item->forceFill(['ordered_qty' => $item->ordered_qty + $qty])->save();

                return $item;
            }

            return $cart->items()->create([
                'sku' => $product->kode,
                'ordered_unit' => $unit->value,
                'ordered_qty' => $qty,
            ]);
        });
    }

    /** Set an exact quantity. Zero removes the line. */
    public function setQuantity(CustomerUser $buyer, CartItem $item, int $qty): void
    {
        $this->assertOwnedBy($buyer, $item);

        if ($qty < 1) {
            $item->delete();

            return;
        }

        $item->forceFill(['ordered_qty' => $qty])->save();
    }

    public function remove(CustomerUser $buyer, CartItem $item): void
    {
        $this->assertOwnedBy($buyer, $item);

        $item->delete();
    }

    public function clear(CustomerUser $buyer): void
    {
        $this->forBuyer($buyer)->items()->delete();
    }

    public function setWarehouse(CustomerUser $buyer, int $warehouseId): void
    {
        $this->forBuyer($buyer)->forceFill(['warehouse_id' => $warehouseId])->save();
    }

    public function itemCount(CustomerUser $buyer): int
    {
        return Cart::query()
            ->where('customer_user_id', $buyer->id)
            ->withCount('items')
            ->value('items_count') ?? 0;
    }

    /**
     * Turn the basket into a submitted order and empty it.
     *
     * The whole thing runs under a row lock on the cart, which is what stops a
     * double-clicked button — or two tabs — becoming two identical orders on
     * the customer's account. The second caller waits, finds an empty basket
     * and is told so, rather than quietly billing them twice.
     *
     * @throws DomainException
     */
    public function checkout(
        CustomerUser $buyer,
        ?string $poPelanggan = null,
        ?string $catatan = null,
    ): Order {
        $cart = $this->forBuyer($buyer);

        /*
         * The four-month freeze, applied at the door rather than at the
         * marketing's desk. A frozen customer's order would only be rejected
         * downstream — letting them build and submit it first is a promise
         * the system already knows it will break. The message names the
         * remedy, because "you are blocked" without "pay this" is a support
         * call that starts angry.
         */
        $jatuhTempo = $this->aging->fallDueInvoices($buyer->company);

        if ($jatuhTempo->isNotEmpty()) {
            throw new DomainException(sprintf(
                'Transaksi baru terkunci: faktur %s belum dibayar lebih dari %d bulan. '
                .'Silakan selesaikan pembayaran itu dulu — hubungi tim kami bila sudah membayar.',
                $jatuhTempo->first()->nomor,
                (int) config('penjualan.debt_freeze_months'),
            ));
        }

        return DB::transaction(function () use ($cart, $buyer, $poPelanggan, $catatan) {
            // Serialise concurrent checkouts of the same basket.
            $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            $items = $locked->items()->with('product')->get();

            if ($items->isEmpty()) {
                throw new DomainException('Keranjang Anda kosong.');
            }

            $warehouseId = $locked->warehouse_id
                ?? Warehouse::query()->where('aktif', true)->value('id');

            if ($warehouseId === null) {
                throw new DomainException('Belum ada gudang aktif. Hubungi tim kami.');
            }

            $lines = $items->map(fn (CartItem $item) => [
                'sku' => $item->sku,
                'ordered_unit' => $item->ordered_unit->value,
                'ordered_qty' => $item->ordered_qty,
            ])->all();

            // BuyerOrderPlacer re-derives qty_base from the product and submits
            // through the state machine. The cart deliberately does not
            // duplicate either job.
            $order = $this->placer->place(
                buyer: $buyer,
                warehouseId: (int) $warehouseId,
                lines: $lines,
                poPelanggan: $poPelanggan,
                catatan: $catatan,
            );

            // Emptied inside the same transaction: an order that exists with a
            // basket still full is how a customer orders twice by refreshing.
            $locked->items()->delete();

            return $order;
        });
    }

    private function orderableProduct(string $sku): Product
    {
        $product = Product::query()->where('kode', $sku)->first();

        if ($product === null || ! $product->aktif) {
            throw new DomainException("Produk {$sku} tidak tersedia.");
        }

        return $product;
    }

    /**
     * A buyer may only touch lines in their own basket.
     *
     * The page already scopes to the signed-in buyer, so reaching this with
     * someone else's line means a hand-made request — which is when a check
     * earns its keep.
     */
    private function assertOwnedBy(CustomerUser $buyer, CartItem $item): void
    {
        if ($item->cart?->customer_user_id !== $buyer->id) {
            throw new DomainException('Baris ini bukan milik keranjang Anda.');
        }
    }
}
