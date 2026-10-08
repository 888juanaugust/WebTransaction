<?php

declare(strict_types=1);

namespace App\Filament\Pages\FixedAssets;

use App\Domain\Access\MenuKey;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Models\FixedAssets\AssetLocation;
use App\Models\FixedAssets\FixedAsset;
use Brick\Math\BigDecimal;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/** Assets by Location: every location with the assets it holds; pick one asset to see where it sits. */
class AssetsByLocation extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.fixed-assets.assets-by-location';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::AssetsByLocation;
    }

    public function mount(): void
    {
        $this->form->fill(['fixed_asset_id' => request()->integer('asset') ?: null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('fixed_asset_id')->label(__('Find an asset'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => FixedAsset::query()->active()
                        ->where(fn ($query) => $query->where('number', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                        ->orderBy('number')->limit(30)->get()->mapWithKeys(fn (FixedAsset $asset) => [$asset->id => "{$asset->number} · {$asset->name}"])->all())
                    ->getOptionLabelUsing(fn ($value) => ($asset = FixedAsset::query()->find($value)) ? "{$asset->number} · {$asset->name}" : null)
                    ->live()->nullable()->native(false),
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
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('name')->label(__('Location'))->weight('medium'),
                TextColumn::make('address')->label(__('Address'))->limit(60)->placeholder('—'),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd(),
                TextColumn::make('assets')->label(__('Assets'))->limit(80)->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('No assets yet'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $assetId = $this->filters['fixed_asset_id'] ?? null;
        $held = FixedAsset::query()->active()
            ->when($assetId, fn ($query) => $query->whereKey($assetId))
            ->orderBy('number')
            ->get()
            ->groupBy(fn (FixedAsset $asset) => $asset->location_id ?? 0);

        $rows = collect();
        foreach (AssetLocation::query()->active()->orderBy('name')->get() as $location) {
            $assets = $held->get($location->id, collect());
            if ($assetId && $assets->isEmpty()) {
                continue;
            }
            $rows->push(self::row($location->id, $location->name, $location->address, $assets));
        }

        $unassigned = $held->get(0, collect());
        if ($unassigned->isNotEmpty()) {
            $rows->push(self::row('unassigned', 'No location', null, $unassigned));
        }

        return $rows;
    }

    /**
     * @param  Collection<int, FixedAsset>  $assets
     * @return array<string, mixed>
     */
    private static function row(int|string $id, string $name, ?string $address, Collection $assets): array
    {
        $quantity = $assets->reduce(fn (BigDecimal $carry, FixedAsset $asset) => $carry->plus((string) $asset->quantity), BigDecimal::zero());

        return [
            'id' => $id,
            'name' => $name,
            'address' => $address,
            'quantity' => Format::quantity((string) $quantity),
            'assets' => $assets->map(fn (FixedAsset $asset) => "{$asset->number} · {$asset->name}")->join(', ') ?: null,
        ];
    }
}
