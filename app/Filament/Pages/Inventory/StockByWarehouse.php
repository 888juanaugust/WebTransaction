<?php

declare(strict_types=1);

namespace App\Filament\Pages\Inventory;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Inventory\StockQuery;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/** Stock by Warehouse: one item, its quantity in every warehouse, in every unit it has. */
class StockByWarehouse extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventory.stock-by-warehouse';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::StockByWarehouse;
    }

    public function mount(): void
    {
        $this->form->fill(['item_id' => request()->integer('item') ?: null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('item_id')->label(__('Item'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Item::query()->active()
                        ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('number', 'ilike', "%{$search}%"))
                        ->orderBy('number')->limit(30)->get()->mapWithKeys(fn (Item $i) => [$i->id => "{$i->number} · {$i->name}"])->all())
                    ->getOptionLabelUsing(fn ($value) => ($i = Item::query()->find($value)) ? "{$i->number} · {$i->name}" : null)
                    ->live()->native(false),
            ]),
        ])->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $seesCost = app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::SeeCost);

        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('warehouse')->label(__('Warehouse'))->weight('medium'),
                TextColumn::make('multi_unit')->label(__('Quantity in each unit')),
                TextColumn::make('available')->label(__('Available stock'))->alignEnd(),
                TextColumn::make('avg_cost')->label(__('Average cost'))->alignEnd()->visible($seesCost),
                TextColumn::make('value')->label(__('Value'))->alignEnd()->visible($seesCost),
                TextColumn::make('address')->label(__('Address'))->limit(50),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Pick an item'))
            ->emptyStateDescription(__('Its stock in every warehouse, counted in every unit it has.'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $itemId = $this->filters['item_id'] ?? null;
        if (! $itemId) {
            return collect();
        }
        $item = Item::query()->with(['unit1', 'units.unit'])->find($itemId);
        if ($item === null) {
            return collect();
        }

        return StockQuery::byWarehouse($item->id)
            ->filter(fn (ItemCost $c) => ! $c->warehouse->is_system || BigDecimal::of((string) $c->qty_on_hand)->isPositive())
            ->map(function (ItemCost $c) use ($item) {
                $base = BigDecimal::of((string) $c->qty_on_hand);
                $parts = [Format::quantity((string) $base).' '.$item->unit1->name];
                foreach ($item->units as $u) {
                    $parts[] = Format::quantity((string) $base->dividedBy((string) $u->ratio, 2, RoundingMode::Down)).' '.$u->unit->name;
                }

                return [
                    'id' => $c->warehouse_id,
                    'warehouse' => $c->warehouse->name.($c->warehouse->is_system ? ' (system)' : ''),
                    'multi_unit' => implode(' · ', $parts),
                    'available' => Format::quantity((string) $base),
                    'avg_cost' => Format::number((int) round((float) $c->avg_cost)),
                    'value' => Format::number($c->total_value),
                    'address' => $c->warehouse->address,
                ];
            })->values();
    }
}
