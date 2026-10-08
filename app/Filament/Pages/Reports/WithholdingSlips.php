<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Access\MenuKey;
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
 * Withholding Slips: each employee's annual A1 slip for a year (the year's
 * income by the slip's rows, occupational cost, pension contributions, PTKP,
 * the tax on the Art. 17 brackets and what was withheld), printable one by
 * one and exported for Coretax. A2 (civil servants) does not apply.
 */
class WithholdingSlips extends ErpPage implements HasTable
{
    use InteractsWithTable;
    use ShowsPph21Filings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected string $view = 'filament.pages.reports.vat-return';

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::WithholdingSlips;
    }

    public function getSubheading(): ?string
    {
        return __('Annual slips (A1) of permanent employees. A2, for civil servants, does not apply.');
    }

    protected function filingKind(): string
    {
        return Pph21Filings::ANNUAL;
    }

    public function mount(): void
    {
        $this->form->fill(['year' => today()->month === 12 ? today()->year : today()->year - 1]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                Select::make('year')->label(__('Year'))->options(IncomeTaxArt21Return::years())->native(false)->selectablePlaceholder(false)->live(),
            ]),
        ])->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    private function year(): int
    {
        return (int) ($this->filters['year'] ?? today()->year);
    }

    public function table(Table $table): Table
    {
        $money = fn (string $name, string $label) => TextColumn::make($name)->label($label)->alignEnd()
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? '' : Format::number((int) $state))->extraCellAttributes(['class' => 'ae-money']);

        return $table
            ->records(fn () => collect($this->rows()))
            ->columns([
                TextColumn::make('name')->label(__('Employee')),
                TextColumn::make('period')->label(__('Months')),
                $money('gross', __('Gross')),
                $money('biaya_jabatan', __('Occupational cost')),
                $money('pension', __('Pension contributions')),
                $money('net_year', __('Net income for the year')),
                $money('ptkp', __('PTKP')),
                $money('pkp', __('Taxable income')),
                $money('tax_due', __('Tax for the year')),
                $money('withheld', __('Withheld')),
                $money('difference', __('Still to withhold')),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn ($state) => $state === __('Final') ? 'success' : 'warning'),
            ])
            ->recordActions([
                Action::make('print')->label(__('Print A1'))->icon('heroicon-m-printer')->color('gray')
                    ->visible(fn (array $record) => ! ($record['is_total'] ?? false))
                    ->url(fn (array $record) => route('filament.admin.a1', ['employee' => $record['id'], 'year' => $this->year()]), shouldOpenInNewTab: true),
            ])
            ->paginated(false)
            ->recordClasses(fn (array $record): ?string => ($record['is_total'] ?? false) ? 'ae-report-total' : null)
            ->emptyStateHeading(__('No payroll in this year'));
    }

    /** @return list<array<string, mixed>> */
    protected function rows(): array
    {
        $rows = [];
        foreach (app(Art21Slips::class)->year($this->year()) as $slip) {
            $rows[] = [
                'id' => (string) $slip['employee']->id,
                'name' => $slip['employee']->name,
                'period' => Months::name($slip['month_start']).' – '.Months::name($slip['month_end']),
                'gross' => $slip['annual']['gross'],
                'biaya_jabatan' => $slip['annual']['biaya_jabatan'],
                'pension' => $slip['pension'],
                'net_year' => $slip['annual']['net_year'],
                'ptkp' => $slip['annual']['ptkp'],
                'pkp' => $slip['annual']['pkp'],
                'tax_due' => $slip['tax_due'],
                'withheld' => $slip['withheld'],
                'difference' => $slip['difference'],
                'status' => $slip['complete'] ? __('Final') : __('Year still running'),
            ];
        }

        return $rows;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportA1')
                ->label(__('Export A1 slips (Coretax XML)'))
                ->icon('heroicon-m-arrow-down-tray')
                ->visible(fn () => static::canUpdate())
                ->requiresConfirmation()
                ->modalDescription(fn () => __('Writes the A1 slips of :year (permanent employees whose year or employment has ended) for Coretax\'s import, numbered from the withholding-slip series.', ['year' => $this->year()]))
                ->action(function () {
                    try {
                        $filing = app(Pph21Filings::class)->exportAnnual($this->year());
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
