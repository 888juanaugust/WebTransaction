<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Reports\InventoryReports;
use App\Models\Inventory\ItemCategory;
use Filament\Forms\Components\Select;

/** Inventory Value by Warehouse (inventory-value): on-hand quantity, average cost and value per item per warehouse. */
class InventoryValue extends ReportPage
{
    public static function reportKey(): string
    {
        return 'inventory-value';
    }

    public static function title(): string
    {
        return __('Inventory Value by Warehouse');
    }

    public static function group(): string
    {
        return 'Inventory';
    }

    public static function description(): string
    {
        return "Quantity on hand, average cost and value of every item per warehouse, from the stock ledger's cost cache.";
    }

    protected function usesPeriod(): bool
    {
        return false;
    }

    protected function usesBranch(): bool
    {
        return false;
    }

    protected function defaultFilters(): array
    {
        return ['warehouse_id' => null, 'category_id' => null];
    }

    protected function extraFilters(): array
    {
        return [
            Select::make('warehouse_id')
                ->label(__('Warehouse'))
                ->options(fn () => InventoryReports::warehouseOptions())
                ->placeholder(__('All warehouses'))
                ->nullable()
                ->native(false)
                ->live(),
            Select::make('category_id')
                ->label(__('Item category'))
                ->options(fn () => ItemCategory::query()->orderBy('name')->pluck('name', 'id'))
                ->placeholder(__('All categories'))
                ->nullable()
                ->native(false)
                ->live(),
        ];
    }

    protected function rows(): array
    {
        $warehouseId = $this->filters['warehouse_id'] ?? null;
        $categoryId = $this->filters['category_id'] ?? null;

        return InventoryReports::inventoryValue($warehouseId ? (int) $warehouseId : null, $categoryId ? (int) $categoryId : null);
    }

    protected function columns(): array
    {
        return [
            static::text('number', __('Item No.'))->fontFamily('mono'),
            static::text('name', __('Item')),
            static::text('category', __('Category')),
            static::text('warehouse', __('Warehouse')),
            static::quantity('quantity', __('On hand')),
            static::quantity('avg_cost', __('Average cost'))->visible(fn (): bool => HakAkses::canSpecial(HakKhusus::SeeCost)),
            static::money('value', __('Value'))->visible(fn (): bool => HakAkses::canSpecial(HakKhusus::SeeCost)),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Item No.'), __('Item'), __('Category'), __('Warehouse'), __('On hand'), __('Average cost'), __('Value')];
    }

    protected function exportRow(array $row): array
    {
        return [
            $row['number'],
            $row['name'],
            $row['category'],
            $row['warehouse'],
            $row['quantity'],
            // Cost leaves only with the "see cost" right.
            HakAkses::canSpecial(HakKhusus::SeeCost) ? $row['avg_cost'] : null,
            HakAkses::canSpecial(HakKhusus::SeeCost) ? $row['value'] : null,
        ];
    }
}
