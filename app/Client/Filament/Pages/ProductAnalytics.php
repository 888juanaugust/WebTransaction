<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Stock\ProductAnalytics as Analytics;
use App\Client\Domain\Stock\StockAge as Age;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Reports\ExcelExport;
use App\Domain\Reports\InventoryReports;
use App\Domain\Reports\Period;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Models\Company\Branch;
use App\Models\Inventory\ItemBrand;
use App\Models\Inventory\ItemCategory;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * Product Analytics: which products are bought most, least and never, which
 * stock is oldest, what comes back, how fast stock turns — six views over one
 * set of filters, read from the ledgers. Cost and margin only with the
 * "see cost" right.
 */
class ProductAnalytics extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.product-analytics';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::ProductAnalytics;
    }

    public function mount(): void
    {
        $this->form->fill(['view' => 'most_sold', 'rank' => 'quantity', 'from' => today()->startOfYear()->toDateString(), 'until' => today()->toDateString(), 'days' => 90]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('view')->label(__('View'))->options(Analytics::viewLabels())->required()->live()->native(false)->afterStateUpdated(fn () => $this->resetTable()),
                Select::make('rank')->label(__('Ranked by'))->options(['quantity' => __('Quantity'), 'value' => __('Net value'), 'customers' => __('Customers')])->live()->native(false)
                    ->visible(fn ($get) => $get('view') === 'most_sold'),
                Select::make('days')->label(__('Idle for'))->options([30 => __(':n days', ['n' => 30]), 90 => __(':n days', ['n' => 90]), 180 => __(':n days', ['n' => 180]), 365 => __(':n days', ['n' => 365])])->live()->native(false)
                    ->visible(fn ($get) => $get('view') === 'least_taken'),
                DatePicker::make('from')->label(__('From'))->native(false)->live()->visible(fn ($get) => in_array($get('view'), ['most_sold', 'most_returned', 'turnover'], true)),
                DatePicker::make('until')->label(__('Until'))->native(false)->live()->visible(fn ($get) => in_array($get('view'), ['most_sold', 'most_returned', 'turnover'], true)),
                Select::make('branch_id')->label(__('Branch'))->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))->live()->native(false)->placeholder(__('Every branch')),
                Select::make('warehouse_id')->label(__('Warehouse'))->options(fn () => InventoryReports::warehouseOptions())->live()->native(false)->placeholder(__('Every warehouse')),
                Select::make('category_id')->label(__('Category'))->options(fn () => ItemCategory::query()->orderBy('name')->pluck('name', 'id'))->live()->native(false)->placeholder(__('Every category')),
                Select::make('brand_id')->label(__('Brand'))->options(fn () => ItemBrand::query()->orderBy('name')->pluck('name', 'id'))->live()->native(false)->placeholder(__('Every brand')),
            ])->columns(4),
        ])->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    public function view(): string
    {
        return (string) ($this->filters['view'] ?? 'most_sold');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        $f = $this->filters ?? [];
        $from = CarbonImmutable::parse((string) ($f['from'] ?? today()->startOfYear()->toDateString()));
        $until = CarbonImmutable::parse((string) ($f['until'] ?? today()->toDateString()));

        return app(Analytics::class)->rows([
            'view' => $this->view(), 'rank' => $f['rank'] ?? 'quantity', 'days' => (int) ($f['days'] ?? 90),
            'period' => new Period($from, $until, $f['branch_id'] ? (int) $f['branch_id'] : null),
            'warehouse_id' => $f['warehouse_id'] ?? null, 'category_id' => $f['category_id'] ?? null, 'brand_id' => $f['brand_id'] ?? null,
        ]);
    }

    /** @return list<array{key: string, label: string, kind: string}> the columns of the view, kind = text|qty|money|int|date|pct */
    public function columnsOf(string $view, bool $seesCost): array
    {
        $item = [['key' => 'number', 'label' => __('Item code'), 'kind' => 'mono'], ['key' => 'name', 'label' => __('Item name'), 'kind' => 'text']];

        return match ($view) {
            'least_taken' => [...$item, ['key' => 'on_hand', 'label' => __('On hand'), 'kind' => 'qty'], ['key' => 'last_out', 'label' => __('Last taken'), 'kind' => 'date'], ['key' => 'idle_days', 'label' => __('Idle (days)'), 'kind' => 'int'], ...($seesCost ? [['key' => 'value', 'label' => __('Value'), 'kind' => 'money']] : [])],
            'never_sold' => [...$item, ['key' => 'on_hand', 'label' => __('On hand'), 'kind' => 'qty'], ...($seesCost ? [['key' => 'value', 'label' => __('Value'), 'kind' => 'money']] : [])],
            'oldest_stock' => [...$item, ['key' => 'warehouse', 'label' => __('Warehouse'), 'kind' => 'text'], ['key' => 'on_hand', 'label' => __('On hand'), 'kind' => 'qty'], ['key' => 'oldest_date', 'label' => __('Oldest since'), 'kind' => 'date'], ['key' => 'oldest_days', 'label' => __('Age (days)'), 'kind' => 'int'], ...($seesCost ? [['key' => 'value', 'label' => __('Value'), 'kind' => 'money']] : [])],
            'most_returned' => [...$item, ['key' => 'returns', 'label' => __('Returns'), 'kind' => 'int'], ['key' => 'quantity', 'label' => __('Returned'), 'kind' => 'qty'], ['key' => 'sold', 'label' => __('Sold'), 'kind' => 'qty'], ['key' => 'rate', 'label' => __('Return rate (%)'), 'kind' => 'pct'], ['key' => 'amount', 'label' => __('Credited'), 'kind' => 'money']],
            'turnover' => [...$item, ['key' => 'sold', 'label' => __('Sold'), 'kind' => 'qty'], ['key' => 'on_hand', 'label' => __('On hand'), 'kind' => 'qty'], ['key' => 'turns', 'label' => __('Turns'), 'kind' => 'pct'], ['key' => 'months_cover', 'label' => __('Months of cover'), 'kind' => 'pct']],
            default => [...$item, ['key' => 'quantity', 'label' => __('Quantity'), 'kind' => 'qty'], ['key' => 'invoices', 'label' => __('Invoices'), 'kind' => 'int'], ['key' => 'customers', 'label' => __('Customers'), 'kind' => 'int'], ['key' => 'amount', 'label' => __('Net value'), 'kind' => 'money'], ...($seesCost ? [['key' => 'cost', 'label' => __('Cost'), 'kind' => 'money'], ['key' => 'margin', 'label' => __('Margin'), 'kind' => 'money']] : [])],
        };
    }

    public function table(Table $table): Table
    {
        $seesCost = HakAkses::canSpecial(HakKhusus::SeeCost);
        $columns = [];
        foreach ($this->columnsOf($this->view(), $seesCost) as $c) {
            $column = TextColumn::make($c['key'])->label($c['label']);
            match ($c['kind']) {
                'mono' => $column->fontFamily('mono')->searchable(),
                'text' => $column->searchable(),
                'qty' => $column->alignEnd()->formatStateUsing(fn ($state) => Format::quantity((string) $state)),
                'money' => $column->alignEnd()->formatStateUsing(fn ($state) => Format::money((int) $state)),
                'int' => $column->alignEnd(),
                'date' => $column->formatStateUsing(fn ($state) => $state ? Format::date($state) : '')->placeholder(__('never')),
                'pct' => $column->alignEnd()->placeholder('—'),
                default => $column,
            };
            $columns[] = $column;
        }

        return $table
            ->records(fn () => $this->rows()->keyBy('key'))
            ->columns($columns)
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('Nothing to show for these filters'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label(__('Export to Excel'))->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->visible(fn () => HakAkses::canSpecial(HakKhusus::ExportData))
                ->action(function (): BinaryFileResponse {
                    $columns = $this->columnsOf($this->view(), HakAkses::canSpecial(HakKhusus::SeeCost));

                    return ExcelExport::download(Analytics::viewLabels()[$this->view()] ?? __('Product Analytics'), __('As of :date', ['date' => Format::date(today())]),
                        array_column($columns, 'label'),
                        $this->rows()->map(fn (array $r) => array_map(fn (array $c) => $r[$c['key']] ?? null, $columns))->all());
                }),
        ];
    }

    /** The top ten of the view by its first number, for the bar chart in the view. @return list<array{label: string, value: float}> */
    public function topTen(): array
    {
        $columns = $this->columnsOf($this->view(), false);
        $numeric = collect($columns)->first(fn (array $c) => in_array($c['kind'], ['qty', 'int', 'money', 'pct'], true));
        if ($numeric === null) {
            return [];
        }

        return $this->rows()->take(10)->map(fn (array $r) => ['label' => (string) $r['number'], 'value' => (float) ($r[$numeric['key']] ?? 0)])->values()->all();
    }

    public static function bucketLabels(): array
    {
        return Age::bucketLabels();
    }
}
