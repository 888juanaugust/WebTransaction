<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Inventory\Units\UnitConverter;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The item, quantity and unit cells every line grid shares. The unit list is
 * the item's units; the base quantity is kept in step for the stock ledger.
 */
final class LineItemFields
{
    /** @param  bool  $groups  whether group items are offered (selling); a group is never bought, received or counted */
    public static function item(string $name = 'item_id', bool $stockedOnly = false, bool $groups = true): Select
    {
        return Select::make($name)
            ->label(__('fields.item'))
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Item::query()->active()
                ->when($stockedOnly, fn ($q) => $q->where('item_type', 'inventory'))
                ->when(! $groups, fn ($q) => $q->where('item_type', '!=', 'group'))
                ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('number', 'ilike', "%{$search}%"))
                ->orderBy('number')->limit(30)->get()
                ->mapWithKeys(fn (Item $i) => [$i->id => "{$i->number} · {$i->name}"])->all())
            ->getOptionLabelUsing(fn ($value) => ($i = Item::query()->find($value)) ? "{$i->number} · {$i->name}" : null)
            ->required()
            ->live()
            ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                $item = $state ? Item::query()->find($state) : null;
                $set('unit_id', $item?->unit1_id);
                self::syncBase($set, $get);
            })
            ->native(false);
    }

    public static function unit(string $name = 'unit_id'): Select
    {
        return Select::make($name)
            ->label(__('fields.unit'))
            ->options(function (Get $get): array {
                $item = $get('item_id') ? Item::query()->with(['unit1', 'units.unit'])->find($get('item_id')) : null;
                if ($item === null) {
                    return Unit::query()->orderBy('name')->pluck('name', 'id')->all();
                }
                $options = [$item->unit1_id => $item->unit1->name];
                foreach ($item->units as $u) {
                    $options[$u->unit_id] = $u->unit->name;
                }

                return $options;
            })
            ->required()
            ->live()
            ->afterStateUpdated(fn (Set $set, Get $get) => self::syncBase($set, $get))
            ->native(false);
    }

    public static function quantity(string $name = 'quantity', ?string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label ?? __('fields.quantity'))
            ->numeric()
            ->required()
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Set $set, Get $get) => self::syncBase($set, $get));
    }

    public static function baseQuantity(): Hidden
    {
        return Hidden::make('base_quantity')->default(0)->dehydrated();
    }

    public static function syncBase(Set $set, Get $get, string $quantityField = 'quantity'): void
    {
        $itemId = $get('item_id');
        $unitId = $get('unit_id');
        $qty = $get($quantityField);
        if (! $itemId || ! $unitId || $qty === null || $qty === '') {
            $set('base_quantity', $qty ?: 0);

            return;
        }
        $item = Item::query()->with('units')->find($itemId);
        try {
            $set('base_quantity', $item ? UnitConverter::toBase($item, (string) $qty, (int) $unitId) : $qty);
        } catch (\Throwable) {
            $set('base_quantity', $qty);
        }
    }

    /** Recomputes base_quantity on every line before saving, in case the live update was skipped. */
    public static function fillBaseQuantities(array $lines, string $quantityField = 'quantity'): array
    {
        foreach ($lines as &$line) {
            if (! empty($line['item_id']) && ! empty($line['unit_id']) && isset($line[$quantityField])) {
                $item = Item::query()->with('units')->find($line['item_id']);
                try {
                    $line['base_quantity'] = $item ? UnitConverter::toBase($item, (string) $line[$quantityField], (int) $line['unit_id']) : $line[$quantityField];
                } catch (\Throwable) {
                    $line['base_quantity'] = $line[$quantityField];
                }
            }
        }

        return $lines;
    }
}
