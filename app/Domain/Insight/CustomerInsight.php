<?php

declare(strict_types=1);

namespace App\Domain\Insight;

use App\Domain\Orders\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a sales should know before walking into the store.
 *
 * Three answers, all computed from the order history rather than kept as
 * state: what this customer has been buying, what the rest of the market
 * buys that they never have, and what they used to buy and stopped. The
 * second and third are the visit's agenda — a recommendation is only worth
 * making when it is either proven demand elsewhere or a lapsed habit here.
 */
class CustomerInsight
{
    /** Statuses that mean the customer actually committed to the goods. */
    private const REAL_SALES = [
        OrderStatus::Confirmed,
        OrderStatus::AwaitingPayment,
        OrderStatus::Paid,
        OrderStatus::Shipped,
        OrderStatus::Completed,
    ];

    /**
     * The customer's recent orders, newest first.
     *
     * @return Collection<int, Order>
     */
    public function history(Company $company, int $limit = 25): Collection
    {
        return Order::query()
            ->where('company_id', $company->id)
            ->with('lines')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Catalogue items this customer has never bought, ranked by how much the
     * rest of the market buys them — proven demand they are missing.
     *
     * @return Collection<int, Product>
     */
    public function neverBought(Company $company, int $limit = 15): Collection
    {
        $boughtSkus = $this->committedLines($company)->distinct()->pluck('order_lines.sku');

        $popularity = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('orders.status', array_map(fn ($s) => $s->value, self::REAL_SALES))
            ->groupBy('order_lines.sku')
            ->select('order_lines.sku', DB::raw('SUM(order_lines.qty_base) AS laku'))
            ->pluck('laku', 'sku');

        return Product::query()
            ->where('aktif', true)
            ->whereNotIn('kode', $boughtSkus)
            ->get()
            ->sortByDesc(fn (Product $p) => (int) ($popularity[$p->kode] ?? 0))
            ->take($limit)
            ->values();
    }

    /**
     * Items the customer bought before the cutoff and never since — the
     * lapsed habits, with when they last ordered each.
     *
     * @return Collection<int, object{sku: string, terakhir: string, total_qty: int}>
     */
    public function stoppedBuying(Company $company, int $days = 90, int $limit = 15): Collection
    {
        return $this->committedLines($company)
            ->groupBy('order_lines.sku')
            ->select(
                'order_lines.sku',
                DB::raw('MAX(orders.created_at) AS terakhir'),
                DB::raw('SUM(order_lines.qty_base) AS total_qty'),
            )
            ->havingRaw('MAX(orders.created_at) < ?', [now()->subDays($days)])
            ->orderByRaw('MAX(orders.created_at) ASC')
            ->limit($limit)
            ->get();
    }

    /** @return Builder */
    private function committedLines(Company $company)
    {
        return DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.company_id', $company->id)
            ->whereIn('orders.status', array_map(fn ($s) => $s->value, self::REAL_SALES));
    }
}
