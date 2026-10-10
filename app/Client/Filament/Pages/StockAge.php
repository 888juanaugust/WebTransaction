<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Stock\StockAge as Age;
use App\Client\Domain\Warehouse\WarehouseScope;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Reports\ExcelExport;
use App\Domain\Reports\InventoryReports;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Models\Inventory\ItemBrand;
use App\Models\Inventory\ItemCategory;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Stock Age: how long the stock on hand has sat in each warehouse, read
 * from the ledger (receipts open layers, issues take the oldest first).
 * One row per item and warehouse with the oldest layer's date and age, the
 * quantity by age bucket and the value at the average cost.
 */
class StockAge extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.stock-age';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::StockAge;
    }

    public function mount(): void
    {
        $bound = WarehouseScope::of(auth()->user());
        $this->form->fill(['warehouse_id' => $bound?->id]);
    }

    public function form(Schema $schema): Schema
    {
        $bound = WarehouseScope::of(auth()->user());

        return $schema->components([
            Section::make()->schema([
                Select::make('warehouse_id')->label(__('Warehouse'))->options(fn () => $bound ? [$bound->id => $bound->name] : InventoryReports::warehouseOptions())
                    ->disabled($bound !== null)->live()->native(false)->placeholder(__('Every warehouse')),
                Select::make('category_id')->label(__('Category'))->options(fn () => ItemCategory::query()->orderBy('name')->pluck('name', 'id'))->live()->native(false)->placeholder(__('Every category')),
                Select::make('brand_id')->label(__('Brand'))->options(fn () => ItemBrand::query()->orderBy('name')->pluck('name', 'id'))->live()->native(false)->placeholder(__('Every brand')),
                Select::make('bucket')->label(__('Age'))->options(Age::bucketLabels())->live()->native(false)->placeholder(__('Any age')),
            ])->columns(4),
        ])->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        $filters = $this->filters ?? [];
        if ($bound = WarehouseScope::of(auth()->user())) {
            $filters['warehouse_id'] = $bound->id;
        }

        return app(Age::class)->rows($filters);
    }

    public function table(Table $table): Table
    {
        $seesCost = HakAkses::canSpecial(HakKhusus::SeeCost);
        $columns = [
            TextColumn::make('number')->label(__('Item code'))->fontFamily('mono')->searchable(),
            TextColumn::make('name')->label(__('Item name'))->searchable()->description(fn (array $record) => collect([$record['brand'], $record['category']])->filter()->join(' · ')),
            TextColumn::make('warehouse')->label(__('Warehouse')),
            TextColumn::make('on_hand')->label(__('On hand'))->alignEnd()->formatStateUsing(fn ($state) => Format::quantity((string) $state)),
            TextColumn::make('oldest_date')->label(__('Oldest since'))->formatStateUsing(fn ($state) => Format::date($state)),
            TextColumn::make('oldest_days')->label(__('Age (days)'))->alignEnd()->sortable(),
            TextColumn::make('bucket')->label(__('Bucket'))->badge()->formatStateUsing(fn ($state) => Age::bucketLabels()[$state] ?? $state)
                ->color(fn ($state) => match ($state) {
                    '0-30' => 'success', '31-90' => 'info', '91-180' => 'warning', default => 'danger'
                }),
        ];
        foreach (Age::bucketLabels() as $key => $label) {
            $columns[] = TextColumn::make("buckets.{$key}")->label($label)->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                ->formatStateUsing(fn ($state) => (float) $state > 0 ? Format::quantity((string) $state) : '');
        }
        if ($seesCost) {
            $columns[] = TextColumn::make('value')->label(__('Value'))->alignEnd()->formatStateUsing(fn ($state) => Format::money((int) $state));
        }

        return $table
            ->records(fn () => $this->rows())
            ->columns($columns)
            ->defaultSort('oldest_days', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('No stock on hand matches'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label(__('Export to Excel'))->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->visible(fn () => HakAkses::canSpecial(HakKhusus::ExportData))
                ->action(function (): BinaryFileResponse {
                    $seesCost = HakAkses::canSpecial(HakKhusus::SeeCost);
                    $headers = [__('Item code'), __('Item name'), __('Warehouse'), __('On hand'), __('Oldest since'), __('Age (days)'), ...array_values(Age::bucketLabels()), ...($seesCost ? [__('Value')] : [])];

                    return ExcelExport::download(__('Stock Age'), __('As of :date', ['date' => Format::date(today())]), $headers,
                        $this->rows()->map(fn (array $r) => [$r['number'], $r['name'], $r['warehouse'], $r['on_hand'], $r['oldest_date'], $r['oldest_days'], ...array_values($r['buckets']), ...($seesCost ? [$r['value']] : [])])->all());
                }),
        ];
    }
}
