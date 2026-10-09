<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Models\PortalCart;
use Illuminate\Console\Command;

/** Deletes carts untouched for the retention period (Preferences have no say: it is client configuration). */
class PruneCartsCommand extends Command
{
    protected $signature = 'central:prune-carts';

    protected $description = 'Delete buyer carts untouched for the retention period';

    public function handle(): int
    {
        $days = max(1, (int) config('portal.cart_retention_days', 90));
        $count = PortalCart::query()->where('updated_at', '<', now()->subDays($days))->delete();
        $this->info("{$count} cart(s) pruned.");

        return self::SUCCESS;
    }
}
