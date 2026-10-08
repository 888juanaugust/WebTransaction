<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Access\MenuKey;
use App\Domain\Company\CompanyIdentity;
use App\Domain\Payroll\Art21Slips;
use App\Domain\Shared\Format;
use App\Domain\Tax\Pph21Filings;
use App\Filament\Support\ErpPage;
use App\Filament\Support\Months;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Income Tax Art. 21 Return: a month's payroll as the tax office sees it,
 * employee by employee (taxable gross, TER category and rate, tax
 * withheld), and the month's BPMP slips exported for Coretax. December, or
 * an employee's last month, is worked out on the year and goes on the A1.
 */
class IncomeTaxArt21Return extends ErpPage implements HasTable
{
    use InteractsWithTable;
    use ShowsPph21Filings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected string $view = 'filament.pages.reports.vat-return';

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::IncomeTaxArt21Return;
    }

    public function getSubheading(): ?string
    {
        return app(CompanyIdentity::class)->taxIdentityLine() ?: null;
    }

    protected function filingKind(): string
    {
        return Pph21Filings::MONTHLY;
    }

    public function mount(): void
    {
        $last = today()->subMonthNoOverflow();
        $this->form->fill(['year' => $last->year, 'month' => $last->month]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                Select::make('month')->label(__('Month'))->options(Months::options())->native(false)->selectablePlaceholder(false)->live(),
                Select::make('year')->label(__('Year'))->options(self::years())->native(false)->selectablePlaceholder(false)->live(),
            ]),
        ])->statePath('filters');
    }

    /** @return array<int, string> */
    public static function years(): array
    {
        $years = range((int) today()->year + 1, (int) today()->year - 5);

        return array_combine($years, array_map('strval', $years));
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    private function year(): int
    {
        return (int) ($this->filters['year'] ?? today()->year);
    }

    private function month(): int
    {
        return (int) ($this->filters['month'] ?? today()->month);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect($this->rows()))
            ->columns([
                TextColumn::make('name')->label(__('Employee')),
                TextColumn::make('tin')->label(__('Tax ID / NIK'))->fontFamily('mono'),
                TextColumn::make('ptkp')->label(__('PTKP')),
                TextColumn::make('category')->label(__('TER category')),
                TextColumn::make('gross')->label(__('Gross'))->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '' : Format::number((int) $state))->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('rate')->label(__('Rate (%)'))->alignEnd(),
                TextColumn::make('tax')->label(__('Income tax'))->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '' : Format::number((int) $state))->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('method')->label(__('Worked out by')),
            ])
            ->paginated(false)
            ->recordClasses(fn (array $record): ?string => ($record['is_total'] ?? false) ? 'ae-report-total' : null)
            ->emptyStateHeading(__('No payroll in this month'));
    }

    /** @return list<array<string, mixed>> */
    protected function rows(): array
    {
        $rows = [];
        $gross = 0;
        $tax = 0;
        foreach (app(Art21Slips::class)->month($this->year(), $this->month()) as $r) {
            $rows[] = [
                'id' => (string) $r['employee']->id,
                'name' => $r['employee']->name,
                'tin' => $r['employee']->npwp_no ?: $r['employee']->nik_no,
                'ptkp' => $r['employee']->tax_status?->value,
                'category' => $r['category'],
                'gross' => $r['gross'],
                'rate' => $r['rate'] !== null ? Format::quantity($r['rate'], 2) : '',
                'tax' => $r['tax'],
                'method' => match ($r['method']) {
                    'ter' => __('Monthly rate (TER)'),
                    'annual' => __('The year, on the A1'),
                    default => __('Not withheld'),
                },
            ];
            $gross += $r['gross'];
            $tax += $r['tax'];
        }
        if ($rows !== []) {
            $rows[] = ['id' => 'total', 'name' => __('Total'), 'tin' => '', 'ptkp' => '', 'category' => '', 'gross' => $gross, 'rate' => '', 'tax' => $tax, 'method' => '', 'is_total' => true];
        }

        return $rows;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportBpmp')
                ->label(__('Export monthly slips (Coretax XML)'))
                ->icon('heroicon-m-arrow-down-tray')
                ->visible(fn () => static::canUpdate())
                ->requiresConfirmation()
                ->modalDescription(fn () => __('Writes the BPMP slips of :month :year (employees taxed at the monthly rate) for Coretax\'s import, numbered from the withholding-slip series.', ['month' => Months::name($this->month()), 'year' => $this->year()]))
                ->action(function () {
                    try {
                        $filing = app(Pph21Filings::class)->exportMonthly($this->year(), $this->month());
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot export'))->body($e->getMessage())->danger()->send();

                        return null;
                    }
                    Notification::make()->title(__(':number: :count slip(s) written', ['number' => $filing->number, 'count' => $filing->document_count]))->success()->send();

                    return response()->download(Storage::disk('local')->path($filing->file_path), $filing->file_name);
                }),
            $this->filingsAction(),
        ];
    }
}
