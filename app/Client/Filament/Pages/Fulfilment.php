<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Warehouse\DeliveryMaker;
use App\Client\Domain\Warehouse\WarehouseScope;
use App\Client\Models\StockReservation;
use App\Client\Screens\CentralScreen;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\Deliveries\DeliveryResource;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpPage;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderLine;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Fulfilment: what the warehouse holds for approved orders — the queue to
 * pick and deliver. A bound gudang account sees its own warehouse and no
 * other; Inventory and the Owner pick a warehouse. Deliver makes the base
 * delivery for what is held, and its print is the surat jalan.
 */
class Fulfilment extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.fulfilment';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::Fulfilment;
    }

    public function getSubheading(): ?string
    {
        $warehouse = $this->warehouse();

        return $warehouse ? __('Warehouse: :name', ['name' => $warehouse->name]) : null;
    }

    /** The warehouse the queue is for: the bound one, else the filter's, else the default. */
    public function warehouse(): ?Warehouse
    {
        if (($bound = WarehouseScope::of(auth()->user())) !== null) {
            return $bound;
        }
        $picked = (int) ($this->tableFilters['warehouse']['value'] ?? 0);
        $query = Warehouse::query()->visibleTo(auth()->user())->where('is_active', true);

        return ($picked > 0 ? (clone $query)->find($picked) : null) ?? (clone $query)->where('is_default', true)->first() ?? $query->orderBy('name')->first();
    }

    public function table(Table $table): Table
    {
        $maker = app(DeliveryMaker::class);

        return $table
            ->query(fn () => $this->queue())
            ->columns([
                TextColumn::make('number')->label(__('Order No.'))->fontFamily('mono')->searchable(),
                TextColumn::make('customer.name')->label(__('fields.customer'))->weight('medium')->searchable(),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('branch.name')->label(__('Branch'))->placeholder('—'),
                TextColumn::make('held')->label(__('Held here'))->wrap()
                    ->state(fn (SalesOrder $record) => collect($this->heldFor($record))->map(fn (array $h) => ($h['line']->item?->name ?? '').' × '.Format::quantity($h['quantity']))->join(', ')),
            ])
            ->filters([
                SelectFilter::make('warehouse')->label(__('Warehouse'))
                    ->options(fn () => Warehouse::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => ! WarehouseScope::isBound(auth()->user()))
                    ->query(fn (Builder $query) => $query),
            ])
            ->defaultSort('trans_date')
            ->recordActions([
                Action::make('pick')->label(__('Pick list'))->icon('heroicon-m-clipboard-document-list')->color('gray')
                    ->modalHeading(fn (SalesOrder $record) => __('Pick list for :number', ['number' => $record->number]))
                    ->modalContent(fn (SalesOrder $record) => view('client.pages.pick-list', ['order' => $record, 'warehouse' => $this->warehouse(), 'held' => $this->heldFor($record)]))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Close')),
                Action::make('deliver')->label(__('Deliver'))->icon('heroicon-m-truck')->color('primary')
                    ->visible(fn () => static::canUpdate())
                    ->modalHeading(fn (SalesOrder $record) => __('Deliver :number from :warehouse', ['number' => $record->number, 'warehouse' => $this->warehouse()?->name]))
                    ->fillForm(fn (SalesOrder $record) => ['lines' => array_map(fn (array $h) => ['line_id' => $h['line']->id, 'item' => ($h['line']->item?->number ?? '').' — '.($h['line']->item?->name ?? ''), 'held' => Format::quantity($h['quantity']), 'quantity' => $h['quantity']], $this->heldFor($record))])
                    ->schema([
                        DatePicker::make('trans_date')->label(__('Delivery date'))->native(false)->required()->default(today()),
                        Repeater::make('lines')->label(__('Lines'))->schema([
                            Hidden::make('line_id'),
                            TextInput::make('item')->label(__('Item'))->disabled()->dehydrated(false)->columnSpan(2),
                            TextInput::make('held')->label(__('Held'))->disabled()->dehydrated(false),
                            TextInput::make('quantity')->label(__('Deliver (base units)'))->numeric()->minValue(0)->required(),
                        ])->columns(4)->addable(false)->deletable(false)->reorderable(false),
                    ])
                    ->action(function (SalesOrder $record, array $data) use ($maker): void {
                        $warehouse = $this->warehouse();
                        if ($warehouse === null) {
                            return;
                        }
                        $quantities = [];
                        foreach ((array) ($data['lines'] ?? []) as $row) {
                            $quantities[(int) ($row['line_id'] ?? 0)] = (string) ($row['quantity'] ?? 0);
                        }
                        try {
                            $delivery = $maker->make($record, $warehouse, $quantities, auth()->user(), (string) $data['trans_date']);
                            Notification::make()->title(__('Delivery :number made', ['number' => $delivery->number]))->success()->send();
                            $this->redirect(DeliveryResource::getUrl('edit', ['record' => $delivery]));
                        } catch (RuntimeException $e) {
                            Notification::make()->title(__('Cannot deliver'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
            ])
            ->emptyStateHeading(__('Nothing to pick'))
            ->emptyStateDescription(__('Approved orders whose goods are held in this warehouse appear here until they are delivered.'));
    }

    /** Approved orders holding stock in the warehouse. */
    private function queue(): Builder
    {
        $warehouse = $this->warehouse();
        if ($warehouse === null) {
            return SalesOrder::query()->whereRaw('1 = 0');
        }
        $holding = StockReservation::query()->select('sales_order_id')
            ->where('warehouse_id', $warehouse->id)
            ->groupBy('sales_order_id')
            ->havingRaw('SUM(quantity) > 0');

        return SalesOrder::query()->with(['customer', 'branch'])->where('approval_status', SalesOrder::APPROVED)->whereIn('id', $holding);
    }

    /** @var array<int, list<array{line: SalesOrderLine, quantity: string}>> */
    private array $held = [];

    /** @return list<array{line: SalesOrderLine, quantity: string}> */
    private function heldFor(SalesOrder $order): array
    {
        $warehouse = $this->warehouse();

        return $warehouse === null ? [] : ($this->held[$order->id] ??= app(DeliveryMaker::class)->held($order, $warehouse));
    }
}
