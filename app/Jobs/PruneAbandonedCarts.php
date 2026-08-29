<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Cart;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Baskets nobody has touched in three months, deleted nightly.
 *
 * A cart holds quantities and never money — no price, no snapshot, nothing
 * any ledger references — so deleting one loses a shopping list, not a
 * record. Ninety days is deliberately long: B2B buyers park a draft order
 * and come back after a holiday, and a basket that vanished is a customer
 * retyping fifteen lines. `updated_at` is the last time anyone worked the
 * basket, because CartItem touches its cart.
 *
 * Idempotent by construction: a second run finds nothing newer to delete.
 */
class PruneAbandonedCarts implements ShouldQueue
{
    use Queueable;

    public const RETENTION_DAYS = 90;

    public function handle(): void
    {
        $stale = Cart::query()
            ->where('updated_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->get();

        foreach ($stale as $cart) {
            $cart->items()->delete();
            $cart->delete();
        }

        $pruned = $stale->count();

        if ($pruned > 0) {
            Log::info('Keranjang terbengkalai dihapus', ['jumlah' => $pruned]);
        }
    }
}
