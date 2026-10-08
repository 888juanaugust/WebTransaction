<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesmanCommissions\Pages;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Sales\CommissionCalculator;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\SalesmanCommissions\SalesmanCommissionResource;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Commission Statement: what each salesperson earned in a period under the
 * commission rules, on the basis Preferences set (which the statement may
 * change). A statement only; nothing is posted.
 */
class CommissionStatement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = SalesmanCommissionResource::class;

    protected string $view = 'filament.sales.commission-statement';

    public ?array $filters = [];

    public function getTitle(): string
    {
        return __('Commission statement');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => today()->startOfMonth()->toDateString(),
            'until' => today()->toDateString(),
            'basis' => (string) app(Preferensi::class)->get(PreferensiKey::CommissionBasis),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                DatePicker::make('from')->label(__('From'))->native(false)->live(),
                DatePicker::make('until')->label(__('Until'))->native(false)->live(),
                Select::make('basis')->label(__('Commission is calculated from'))->options(PreferensiKey::CommissionBasis->options())->native(false)->selectablePlaceholder(false)->live(),
            ]),
        ])->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $rows = app(CommissionCalculator::class)->statement($this->filters['from'] ?? today()->startOfMonth(), $this->filters['until'] ?? today(), $this->filters['basis'] ?? null);
        $out = array_map(fn (array $r) => ['key' => (string) ($r['salesman_id'] ?? 'none')] + $r + ['detail' => collect($r['rules'])->map(fn ($x) => $x['name'].' '.Format::number($x['amount']))->join(' · ')], $rows);
        if ($out !== []) {
            $out[] = ['key' => 'total', 'name' => __('Total'), 'sales' => array_sum(array_column($rows, 'sales')), 'quantity' => '', 'profit' => array_sum(array_column($rows, 'profit')), 'commission' => array_sum(array_column($rows, 'commission')), 'detail' => '', 'is_total' => true];
        }

        return $out;
    }

    public function table(Table $table): Table
    {
        $money = fn (string $name, string $label) => TextColumn::make($name)->label($label)->alignEnd()
            ->formatStateUsing(fn ($state) => $state === '' || $state === null ? '' : Format::number((int) $state))->extraCellAttributes(['class' => 'ae-money']);

        return $table
            ->records(fn () => collect($this->rows())->keyBy('key'))
            ->columns([
                TextColumn::make('name')->label(__('Salesperson')),
                $money('sales', __('Net sales')),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd()->formatStateUsing(fn ($state) => $state === '' ? '' : Format::quantity((string) $state)),
                $money('profit', __('Gross profit'))->visible(fn () => HakAkses::canSpecial(HakKhusus::SeeCost)),
                $money('commission', __('Commission')),
                TextColumn::make('detail')->label(__('Rules applied'))->wrap()->placeholder('—'),
            ])
            ->recordClasses(fn (array $record): ?string => ($record['is_total'] ?? false) ? 'ae-report-total' : null)
            ->paginated(false)
            ->emptyStateHeading(__('No sales in this period'));
    }
}
