<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Reports\InventoryReports;
use App\Models\Inventory\Item;
use Filament\Forms\Components\Select;

/** Stock Card (stock-card): one item's movements with the running balance. */
class StockCard extends ReportPage
{
    public static function reportKey(): string
    {
        return 'stock-card';
    }

    public static function title(): string
    {
        return __('Stock Card');
    }

    public static function group(): string
    {
        return 'Inventory';
    }

    public static function description(): string
    {
        return "One item's movements in and out with the running quantity and value, per warehouse or across all.";
    }

    protected function usesBranch(): bool
    {
        return false;
    }

    protected function defaultFilters(): array
    {
        return parent::defaultFilters() + ['item_id' => null, 'warehouse_id' => null];
    }

    protected function extraFilters(): array
    {
        return [
            Select::make('item_id')
                ->label(__('Item'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => InventoryReports::itemOptions($search))
                ->getOptionLabelUsing(fn ($value) => ($item = Item::query()->find($value)) ? "{$item->number} · {$item->name}" : null)
                ->placeholder(__('Choose an item'))
                ->native(false)
                ->live(),
            Select::make('warehouse_id')
                ->label(__('Warehouse'))
                ->options(fn () => InventoryReports::warehouseOptions())
                ->placeholder(__('All warehouses'))
                ->nullable()
                ->native(false)
                ->live(),
        ];
    }

    protected function rows(): array
    {
        $itemId = $this->filters['item_id'] ?? null;
        if ($itemId === null || $itemId === '') {
            return [];
        }

        $warehouseId = $this->filters['warehouse_id'] ?? null;

        return InventoryReports::stockCard((int) $itemId, $this->period(), $warehouseId ? (int) $warehouseId : null);
    }

    protected function columns(): array
    {
        return [
            static::date('trans_date', __('Date')),
            static::text('source', __('Source')),
            static::text('warehouse', __('Warehouse')),
            static::quantity('in', __('In')),
            static::quantity('out', __('Out')),
            static::quantity('unit_cost', __('Unit cost'))->visible(fn (): bool => HakAkses::canSpecial(HakKhusus::SeeCost)),
            static::quantity('balance_qty', __('Balance qty')),
            static::money('balance_value', __('Balance value'))->visible(fn (): bool => HakAkses::canSpecial(HakKhusus::SeeCost)),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Date'), __('Source'), __('Warehouse'), __('In'), __('Out'), __('Unit cost'), __('Balance qty'), __('Balance value')];
    }

    protected function exportRow(array $row): array
    {
        return [
            $row['trans_date'],
            $row['source'],
            $row['warehouse'],
            $row['in'],
            $row['out'],
            // Cost leaves only with the "see cost" right.
            HakAkses::canSpecial(HakKhusus::SeeCost) ? $row['unit_cost'] : null,
            $row['balance_qty'],
            HakAkses::canSpecial(HakKhusus::SeeCost) ? $row['balance_value'] : null,
        ];
    }
}
