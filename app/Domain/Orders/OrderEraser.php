<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Access\Role;
use App\Domain\Audit\AuditLogger;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Erasing an unfinished order — marketing clearing the clutter.
 *
 * Only `draft` and `submitted` qualify. Everything from `confirmed` on has
 * consequences attached — snapshotted prices, reserved stock, an invoice —
 * and those are ended through the state machine (reject, expire), which
 * releases what was held and keeps the trail. An unfinished order holds
 * nothing, so it may simply go.
 *
 * The erasure itself must not be silent: the audit entry carries the order's
 * summary, because the row it describes will no longer exist to ask.
 */
class OrderEraser
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function erase(Order $order, User $actor, ?string $alasan = null): void
    {
        if (! in_array($order->status, [OrderStatus::Draft, OrderStatus::Submitted], true)) {
            throw new \DomainException(
                "Order {$order->nomor} berstatus {$order->status->label()} — hanya draf dan yang menunggu persetujuan yang bisa dihapus. "
                .'Order yang sudah dikonfirmasi ditolak lewat alur biasa, bukan dihapus.'
            );
        }

        $this->assertMayErase($order, $actor);

        DB::transaction(function () use ($order, $actor, $alasan) {
            /*
             * The snapshot is written first and survives the delete —
             * "what was erased" must be answerable without the row.
             */
            $this->audit->log(
                action: 'order_erased',
                subject: $order,
                oldValue: [
                    'nomor' => $order->nomor,
                    'pelanggan' => $order->company->nama,
                    'status' => $order->status->value,
                    'total_rupiah' => $order->total_rupiah,
                    'baris' => $order->lines()->count(),
                ],
                actor: $actor,
                alasan: $alasan,
            );

            // Lines and events cascade; anything else referencing the order
            // (a deposit, an invoice) makes the database refuse — which is
            // correct, because then it was not unfinished.
            $order->delete();
        });
    }

    /**
     * The same seat that owns the customer's pending queue: their marketing,
     * or the Owner. Sales cannot erase — a submitted order is already a
     * claim on marketing's attention, and clearing it is their call.
     */
    private function assertMayErase(Order $order, User $actor): void
    {
        if ($actor->role() === Role::Owner) {
            return;
        }

        if ($actor->role() !== Role::Marketing) {
            throw new \DomainException('Menghapus order yang belum jadi hanya bisa dilakukan marketing penanggung jawab pelanggan (atau pemilik).');
        }

        $marketingId = $order->company->marketing_user_id;

        if ($marketingId === null || (int) $marketingId !== (int) $actor->getKey()) {
            throw new \DomainException('Order ini milik pelanggan yang diurus marketing lain — bukan Anda.');
        }
    }
}
