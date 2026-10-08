<?php

declare(strict_types=1);

namespace App\Filament\Pages\Inventory;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\MenuKey;
use App\Domain\Inventory\Replenishment;
use App\Domain\Inventory\StockQuery;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\Deliveries\DeliveryResource;
use App\Filament\Support\ErpPage;
use App\Models\Sales\SalesOrder;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/** Order Fulfilment: approved sales orders not yet delivered, what shipped and what stock can ship now. */
class OrderFulfilment extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventory.order-fulfilment';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function menuKey(): MenuKey
    {
        return MenuKey::OrderFulfilment;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('customer')->label(__('fields.customer'))->weight('medium'),
                TextColumn::make('number')->label(__('Order No.'))->fontFamily('mono'),
                TextColumn::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('ship_date')->label(__('fields.ship_date')),
                TextColumn::make('delivered')->label(__('Delivered'))->alignEnd(),
                TextColumn::make('deliverable')->label(__('Can ship now'))->alignEnd()->color(fn ($state) => str_starts_with((string) $state, '0') ? 'danger' : 'success'),
                TextColumn::make('need_to_order')->label(__('Need to order'))->alignEnd()->color(fn ($state) => $state === Format::quantity('0') ? null : 'danger')
                    ->tooltip(__('What stock on hand and open purchase orders cannot cover, the earliest orders served first.')),
            ])
            ->recordActions([
                Action::make('deliver')->label(__('Deliver'))->icon('heroicon-m-truck')
                    ->visible(fn () => DeliveryResource::canCreate())
                    ->url(fn (array $record) => DeliveryResource::getUrl('create', ['source' => $record['id']])),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Every approved order has shipped'))
            ->emptyStateDescription(__('Approved orders with lines still to deliver appear here.'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $onHand = StockQuery::onHandMap();
        // What can still cover the orders: stock on hand plus what open purchase orders bring, used up earliest order first.
        $onOrder = Replenishment::onOrder();
        $cover = [];
        foreach (array_keys($onHand + $onOrder) as $itemId) {
            $cover[$itemId] = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($onHand[$itemId] ?? '0'))->plus($onOrder[$itemId] ?? '0');
        }

        return BranchLimit::apply(SalesOrder::query(), auth()->user())->with(['customer', 'lines'])
            ->where('approval_status', SalesOrder::APPROVED)
            ->whereIn('status', ['pending', 'partial'])
            ->orderBy('ship_date')->orderBy('trans_date')
            ->get()
            ->map(function (SalesOrder $order) use ($onHand, &$cover) {
                $ordered = BigDecimal::zero();
                $processed = BigDecimal::zero();
                $deliverable = BigDecimal::zero();
                $short = BigDecimal::zero();
                foreach ($order->lines as $line) {
                    $left = BigDecimal::of($line->remainingQuantity());
                    $available = $cover[$line->item_id] ?? BigDecimal::zero();
                    $taken = $left->isLessThan($available) ? $left : $available;
                    $cover[$line->item_id] = $available->minus($taken);
                    $short = $short->plus($left->minus($taken));
                    $ordered = $ordered->plus((string) $line->base_quantity);
                    $processed = $processed->plus((string) $line->processed_quantity);
                    $remaining = BigDecimal::of($line->remainingQuantity());
                    $stock = BigDecimal::of($onHand[$line->item_id] ?? '0');
                    $deliverable = $deliverable->plus($remaining->isLessThan($stock) ? $remaining : ($stock->isNegative() ? BigDecimal::zero() : $stock));
                }

                return [
                    'id' => $order->id,
                    'customer' => $order->customer->name,
                    'number' => $order->number,
                    'trans_date' => Format::date($order->trans_date),
                    'ship_date' => Format::date($order->ship_date),
                    'delivered' => Format::quantity((string) $processed).' / '.Format::quantity((string) $ordered),
                    'deliverable' => __(':part of :whole', ['part' => Format::quantity((string) $deliverable), 'whole' => Format::quantity((string) $ordered->minus($processed))]),
                    'need_to_order' => Format::quantity((string) $short),
                ];
            })->values();
    }
}
