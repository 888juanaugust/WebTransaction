<?php

declare(strict_types=1);

namespace App\Filament\Pages\Inventory;

use App\Domain\Access\MenuKey;
use App\Domain\Inventory\Replenishment;
use App\Domain\Shared\Format;
use App\Filament\Resources\Purchasing\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Purchasing\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Filament\Support\ErpPage;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\Vendor;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/** Minimum Stock: the items at or below their minimum, by vendor and warehouse, with what is on order and requested; select and Order or Request. */
class MinimumStock extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventory.minimum-stock';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::MinimumStock;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                Select::make('vendor_id')->label(__('Vendor'))->options(fn () => Vendor::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->live()->native(false),
                Select::make('warehouse_id')->label(__('Warehouse'))->options(fn () => Warehouse::query()->where('is_system', false)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->live()->native(false)->placeholder(__('All warehouses')),
                TextInput::make('search')->label(__('Item name or code'))->live(onBlur: true),
            ]),
        ])->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows()->keyBy('id'))
            ->columns([
                TextColumn::make('vendor')->label(__('Vendor')),
                TextColumn::make('name')->label(__('Item name'))->weight('medium'),
                TextColumn::make('number')->label(__('Item code'))->fontFamily('mono'),
                TextColumn::make('unit')->label(__('Unit')),
                TextColumn::make('on_hand')->label(__('Available'))->alignEnd(),
                TextColumn::make('on_order')->label(__('On order'))->alignEnd(),
                TextColumn::make('requested')->label(__('Requested'))->alignEnd(),
                TextColumn::make('min_stock')->label(__('Minimum'))->alignEnd(),
                TextColumn::make('to_order')->label(__('To order'))->alignEnd()->weight('medium'),
            ])
            ->selectable()
            ->toolbarActions([
                BulkAction::make('order')->label(__('Order'))->icon('heroicon-m-shopping-cart')
                    ->visible(fn () => PurchaseOrderResource::canCreate())
                    ->action(fn (Collection $records) => $this->redirect(PurchaseOrderResource::getUrl('create', $this->reorderQuery($records)))),
                BulkAction::make('request')->label(__('Request'))->icon('heroicon-m-clipboard-document-list')->color('gray')
                    ->visible(fn () => PurchaseRequisitionResource::canCreate())
                    ->action(fn (Collection $records) => $this->redirect(PurchaseRequisitionResource::getUrl('create', $this->reorderQuery($records)))),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Nothing below its minimum'))
            ->emptyStateDescription(__('Items whose stock is at or under the minimum set on the item (or for the warehouse) appear here.'));
    }

    /** ?reorder=item:quantity,… (and the warehouse) for the purchase order or requisition the selection opens. */
    private function reorderQuery(Collection $records): array
    {
        return array_filter([
            'reorder' => $records->map(fn (array $r) => $r['id'].':'.$r['to_order_raw'])->join(','),
            'warehouse' => $this->filters['warehouse_id'] ?? null,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        return Replenishment::belowMinimum(
            ($this->filters['warehouse_id'] ?? null) ? (int) $this->filters['warehouse_id'] : null,
            ($this->filters['vendor_id'] ?? null) ? (int) $this->filters['vendor_id'] : null,
            trim((string) ($this->filters['search'] ?? '')),
        )->map(fn (array $r) => [
            'id' => $r['item']->id,
            'vendor' => $r['item']->preferredVendor?->name ?? '—',
            'name' => $r['item']->name,
            'number' => $r['item']->number,
            'unit' => $r['item']->unit1->name,
            'on_hand' => Format::quantity($r['on_hand']),
            'on_order' => Format::quantity($r['on_order']),
            'requested' => Format::quantity($r['requested']),
            'min_stock' => Format::quantity($r['minimum']),
            'to_order' => Format::quantity($r['to_order']),
            'to_order_raw' => $r['to_order'],
        ]);
    }
}
