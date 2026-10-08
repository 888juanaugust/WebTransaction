<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\FixedAssets\FixedAsset;
use Filament\Forms\Components\Select;

/** The asset lookup every fixed-asset document opens with: active assets, found by number or name. */
final class AssetFields
{
    public static function select(string $name = 'fixed_asset_id', ?string $label = null): Select
    {
        return Select::make($name)
            ->label($label ?? __('Asset'))
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => FixedAsset::query()->active()
                ->where(fn ($query) => $query->where('number', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                ->orderBy('number')->limit(30)->get()
                ->mapWithKeys(fn (FixedAsset $a) => [$a->id => "{$a->number} · {$a->name}"])->all())
            ->getOptionLabelUsing(fn ($value) => ($a = FixedAsset::query()->find($value)) ? "{$a->number} · {$a->name}" : null)
            ->required()
            ->native(false)
            ->live();
    }
}
