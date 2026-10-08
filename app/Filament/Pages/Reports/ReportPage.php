<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Company\FiscalYear;
use App\Domain\Currency\Convert;
use App\Domain\Currency\Currencies;
use App\Domain\Reports\ExcelExport;
use App\Domain\Reports\Period;
use App\Domain\Shared\Format;
use App\Filament\Support\BranchFields;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\ErpPage;
use App\Filament\Support\TagFields;
use App\Modules\ModuleRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One report of the catalogue: its filters, its rows computed from the
 * ledgers on every run, shown in a table and exported to a spreadsheet as
 * shown. Reached from the Report Catalogue, guarded by its right.
 */
abstract class ReportPage extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected string $view = 'filament.pages.reports.report';

    public ?array $filters = [];

    /** The URL part and the catalogue key, e.g. 'balance-sheet'. */
    abstract public static function reportKey(): string;

    abstract public static function title(): string;

    /** Financial · Sales · Purchasing · Inventory · Cash & Bank · Fixed Assets */
    abstract public static function group(): string;

    abstract public static function description(): string;

    /** @return list<array<string, mixed>> rows, each with an 'id'; a row with 'is_total' or 'is_heading' is styled so */
    abstract protected function rows(): array;

    /** @return list<TextColumn> */
    abstract protected function columns(): array;

    /** @return list<string> */
    abstract protected function exportHeaders(): array;

    /** @return list<scalar|null> the row as the spreadsheet shows it */
    abstract protected function exportRow(array $row): array;

    public static function menuKey(): MenuKey
    {
        return MenuKey::ReportCatalogue;
    }

    /** The module key this report needs switched on, or null for a report of the core. */
    public static function requires(): ?string
    {
        return null;
    }

    public static function available(): bool
    {
        return static::requires() === null || app(ModuleRegistry::class)->isEnabled(static::requires());
    }

    public static function canAccess(): bool
    {
        return static::available() && parent::canAccess();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'report/'.static::reportKey();
    }

    public function getTitle(): string
    {
        return static::title();
    }

    public function getSubheading(): ?string
    {
        return static::description();
    }

    /** Which filters this report takes; override to add or drop. */
    protected function usesPeriod(): bool
    {
        return true;
    }

    protected function usesBranch(): bool
    {
        return true;
    }

    /** Whether the report filters by department and project (shown only while those modules are on). */
    protected function usesTags(): bool
    {
        return false;
    }

    /** Whether the report opens on the fiscal year to date (the income statement, say) rather than this month. */
    protected function yearToDate(): bool
    {
        return false;
    }

    protected function defaultFrom(): string
    {
        return $this->yearToDate() ? FiscalYear::startOf()->toDateString() : today()->startOfMonth()->toDateString();
    }

    /** @return array<string, mixed> */
    protected function defaultFilters(): array
    {
        return [
            'from' => $this->defaultFrom(),
            'until' => today()->toDateString(),
            'branch_id' => BranchFields::reportBranch(null),
            'department_id' => null,
            'project_id' => null,
        ];
    }

    /** Extra filter fields, placed after the period. @return list<\Filament\Schemas\Components\Component> */
    protected function extraFilters(): array
    {
        return [];
    }

    public function mount(): void
    {
        $this->form->fill($this->defaultFilters());
    }

    public function form(Schema $schema): Schema
    {
        $fields = [];
        if ($this->usesPeriod()) {
            $fields[] = DatePicker::make('from')->label(__('From'))->native(false)->live();
            $fields[] = DatePicker::make('until')->label(__('Until'))->native(false)->live();
        }
        if ($this->usesBranch()) {
            $fields[] = BranchFields::filter();
        }
        if ($this->usesTags()) {
            array_push($fields, ...TagFields::filters());
        }

        return $schema->components([Section::make()->columns(4)->schema(array_merge($fields, $this->extraFilters()))])->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    protected function period(): Period
    {
        return new Period(
            $this->filters['from'] ?? $this->defaultFrom(),
            $this->filters['until'] ?? today()->toDateString(),
            BranchFields::reportBranch($this->filters['branch_id'] ?? null),
            $this->usesTags() ? TagFields::picked($this->filters['department_id'] ?? null, 'departments') : null,
            $this->usesTags() ? TagFields::picked($this->filters['project_id'] ?? null, 'projects') : null,
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect($this->rows()))
            ->columns($this->columns())
            ->paginated(false)
            ->recordClasses(fn (array $record): ?string => match (true) {
                (bool) ($record['is_total'] ?? false) => 'ae-report-total',
                (bool) ($record['is_heading'] ?? false) => 'ae-report-heading',
                default => null,
            })
            ->emptyStateHeading(__('Nothing in this period'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Export to Excel'))
                ->visible(fn () => HakAkses::canSpecial(HakKhusus::ExportData))
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn (): BinaryFileResponse => ExcelExport::download(
                    static::title(),
                    $this->usesPeriod() ? $this->period()->label() : 'As of '.Format::date(today()),
                    $this->exportHeaders(),
                    array_map(fn (array $row) => $this->exportRow($row), $this->rows()),
                )),
        ];
    }

    // --- column helpers every report shares ---------------------------------

    protected static function money(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->alignEnd()
            ->formatStateUsing(fn ($state, $livewire): string => $state === '' || $state === null ? '' : ($livewire instanceof self ? $livewire->formatMoney((int) $state) : Format::number((int) $state)))
            ->extraCellAttributes(['class' => 'ae-money']);
    }

    /** An amount of the report: whole base units, or the minor units of the currency the report is filtered to. */
    public function formatMoney(int $amount): string
    {
        return CurrencyFields::number($amount, $this->reportCurrency());
    }

    /** The foreign currency the report is filtered to; null: every currency, in the base currency. */
    protected function reportCurrency(): ?int
    {
        $currencyId = $this->filters['currency_id'] ?? null;

        return Currencies::isForeign($currencyId) ? (int) $currencyId : null;
    }

    /** An amount for the spreadsheet: as it is, or in major units of the report's foreign currency. */
    protected function exportMoney(mixed $amount): mixed
    {
        $currencyId = $this->reportCurrency();
        if ($currencyId === null || $amount === null || $amount === '') {
            return $amount;
        }

        return (float) Convert::major((int) $amount, Currencies::decimals($currencyId));
    }

    /** "Currency": every currency in the base currency (the default), or one foreign currency in its own amounts. */
    protected static function currencyFilter(): Select
    {
        return Select::make('currency_id')
            ->label(__('Currency'))
            ->options(fn () => Currencies::foreignOptions())
            ->placeholder(fn () => __('All, in :code', ['code' => Currencies::base()?->code]))
            ->native(false)
            ->live()
            ->visible(fn () => Currencies::enabled());
    }

    protected static function text(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label);
    }

    protected static function date(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->formatStateUsing(fn ($state): string => $state ? Format::date($state) : '');
    }

    protected static function quantity(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->alignEnd()->formatStateUsing(fn ($state): string => $state === '' || $state === null ? '' : Format::quantity($state));
    }
}
