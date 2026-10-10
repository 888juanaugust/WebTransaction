<?php

declare(strict_types=1);

namespace App\Client\Domain\Orders;

use App\Client\Domain\Notify\Notify;
use App\Client\Domain\Stock\Reservations;
use App\Client\Mail\DeliveryDigestMessage;
use App\Client\Models\OrderDeliveryNotice;
use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\DeliveryLine;
use App\Models\Sales\SalesOrder;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The 30-day watch. Thirty days after an order was accepted (approved by
 * the marketing seat or the Owner) the administrators and the customer's
 * marketing seat are told whether its goods went out: delivered, partly,
 * or not at all and which warehouse holds them. Once per order, in one
 * daily digest, by bell and by mail. Nothing is released: the admin
 * rejects or edits the order, which gives the stock back.
 */
final class DeliveryWatch
{
    public function __construct(private readonly Reservations $reservations, private readonly Notify $notify) {}

    public function days(): int
    {
        return max(1, (int) config('orders.watch_days', 30));
    }

    /** Accepted orders past the watch days not yet reported, oldest first. @return Collection<int, SalesOrder> */
    public function due(): Collection
    {
        return $this->watched()
            ->whereDate('approved_at', '<=', today()->subDays($this->days())->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_delivery_notices')->whereColumn('order_delivery_notices.sales_order_id', 'sales_orders.id'))
            ->orderBy('approved_at')
            ->get();
    }

    /** Every accepted order past the watch days, reported or not: the worklist. */
    public function overdue(): Builder
    {
        return $this->watched()->whereDate('approved_at', '<=', today()->subDays($this->days())->toDateString());
    }

    /** @return array{state: string, ordered: string, delivered: string, deliveries: list<string>, warehouses: list<string>, days: int} */
    public function summary(SalesOrder $order): array
    {
        $ordered = BigDecimal::zero();
        $delivered = BigDecimal::zero();
        $lineIds = [];
        foreach ($order->lines as $line) {
            $ordered = $ordered->plus(BigDecimal::of((string) $line->base_quantity));
            $delivered = $delivered->plus(BigDecimal::of((string) $line->processed_quantity));
            $lineIds[] = $line->id;
        }
        $deliveries = $lineIds === [] ? [] : DeliveryLine::query()->where('source_line_type', 'sales_order_line')->whereIn('source_line_id', $lineIds)
            ->join('deliveries', 'deliveries.id', '=', 'delivery_lines.delivery_id')->distinct()->orderBy('deliveries.number')->pluck('deliveries.number')->all();
        $warehouses = collect($this->reservations->heldByOrder($order))->pluck('warehouse')->unique()
            ->map(fn (int $id) => Warehouse::query()->find($id)?->name)->filter()->values()->all();
        $state = $delivered->isZero() ? 'pending' : ($delivered->isGreaterThanOrEqualTo($ordered) ? 'delivered' : 'partial');

        return [
            'state' => $state,
            'ordered' => $ordered->toScale(4)->__toString(),
            'delivered' => $delivered->toScale(4)->__toString(),
            'deliveries' => $deliveries,
            'warehouses' => $warehouses,
            'days' => $order->approved_at ? (int) CarbonImmutable::parse($order->approved_at)->startOfDay()->diffInDays(today(), false) : 0,
        ];
    }

    /** Reports every order due today in one digest; returns how many. */
    public function digest(): int
    {
        $due = $this->due();
        if ($due->isEmpty()) {
            return 0;
        }
        $rows = [];
        $recipients = $this->notify->administrators();
        foreach ($due as $order) {
            $summary = $this->summary($order);
            $inserted = OrderDeliveryNotice::query()->insertOrIgnore([
                'sales_order_id' => $order->id, 'state' => $summary['state'], 'ordered_base_qty' => $summary['ordered'], 'delivered_base_qty' => $summary['delivered'],
                'days' => $summary['days'], 'created_at' => now(),
            ]);
            if ($inserted === 0) {
                continue; // reported by another run meanwhile
            }
            $rows[] = ['order' => $order, 'summary' => $summary];
            if ($order->customer) {
                $recipients = $recipients->concat($this->notify->team($order->customer)->filter(fn ($u) => $u->id === $order->customer->marketing_user_id));
            }
        }
        if ($rows === []) {
            return 0;
        }
        $title = __(':n order(s) accepted :days days ago or more', ['n' => count($rows), 'days' => $this->days()]);
        $body = collect($rows)->map(fn (array $r) => $r['order']->number.': '.self::stateLabel($r['summary']['state']))->take(5)->join(' · ');
        $sent = $this->notify->send($recipients, $title, $body, SalesOrderResource::getUrl(), new DeliveryDigestMessage($rows, $this->days()));
        OrderDeliveryNotice::query()->whereIn('sales_order_id', collect($rows)->map(fn (array $r) => $r['order']->id)->all())
            ->update(['sent_to' => DB::raw("'".json_encode($sent)."'::jsonb"), 'sent_at' => now()]);

        return count($rows);
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'delivered' => __('delivered'),
            'partial' => __('partly delivered'),
            default => __('not yet delivered'),
        };
    }

    private function watched(): Builder
    {
        return SalesOrder::query()->with(['customer', 'lines', 'branch'])->where('approval_status', SalesOrder::APPROVED)->whereNotNull('approved_at');
    }
}
