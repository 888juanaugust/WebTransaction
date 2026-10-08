<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tax;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Shared\Format;
use App\Domain\Tax\FilingDocuments;
use App\Domain\Tax\TaxFilingService;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\ErpPage;
use App\Models\Sales\SalesInvoice;
use App\Models\Tax\TaxFiling;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * e-Tax Invoice Export: the period's tax invoices, exported to the tax
 * office's bulk-import file; the serial numbers it hands back are pasted in
 * here.
 */
class ETaxInvoiceExport extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.tax.e-tax-invoice-export';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::ETaxInvoiceExport;
    }

    /** Which file this screen writes: the tax office's current layout here, the older one on the legacy screen. */
    protected function format(): string
    {
        return TaxFiling::CORETAX;
    }

    public function mount(): void
    {
        $this->form->fill([
            'kind' => TaxFiling::OUT,
            'month' => (int) today()->format('n'),
            'year' => (int) today()->format('Y'),
            'day_from' => 1,
            'day_to' => 31,
            'branch_id' => BranchFields::reportBranch(null),
            'document' => 'all',
            'status' => 'all',
            'search' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $year = (int) today()->format('Y');
        $months = collect(range(1, 12))->mapWithKeys(fn (int $m) => [$m => CarbonImmutable::create($year, $m, 1)->translatedFormat('F')])->all();
        $days = collect(range(1, 31))->mapWithKeys(fn (int $d) => [$d => (string) $d])->all();

        return $schema
            ->components([
                Section::make()->columns(4)->schema([
                    Select::make('kind')->label(__('Tax'))
                        ->options([TaxFiling::OUT => __('VAT out (sales)'), TaxFiling::IN => __('VAT in (purchases)')])
                        ->default(TaxFiling::OUT)->native(false)->selectablePlaceholder(false)->live(),
                    Select::make('month')->label(__('Month'))->options($months)->default((int) today()->format('n'))->native(false)->selectablePlaceholder(false)->live(),
                    Select::make('year')->label(__('Year'))
                        ->options(collect(range($year - 2, $year + 1))->mapWithKeys(fn (int $y) => [$y => (string) $y])->all())
                        ->default($year)->native(false)->selectablePlaceholder(false)->live(),
                    Select::make('day_from')->label(__('From day'))->options($days)->default(1)->native(false)->selectablePlaceholder(false)->live(),
                    Select::make('day_to')->label(__('To day'))->options($days)->default(31)->native(false)->selectablePlaceholder(false)->live(),
                    BranchFields::filter(),
                    Select::make('document')->label(__('Document'))
                        ->options(['all' => __('All'), 'invoice' => __('Tax invoice'), 'aggregated' => __('Aggregated (no buyer tax ID)')])
                        ->default('all')->native(false)->selectablePlaceholder(false)->live(),
                    Select::make('status')->label(__('Status'))
                        ->options(['all' => __('All'), 'draft' => __('Draft'), 'exported' => __('Exported'), 'numbered' => __('Numbered')])
                        ->default('all')->native(false)->selectablePlaceholder(false)->live(),
                    TextInput::make('search')->label(__('Search'))->placeholder(__('Number, serial or name'))->live(onBlur: true),
                ]),
            ])
            ->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => FilingDocuments::narrow(
                FilingDocuments::query($this->kind(), $this->from(), $this->until(), $this->branchId(), $this->filters['search'] ?? null),
                $this->kind(), $this->filters['document'] ?? 'all', $this->filters['status'] ?? 'all',
            ))
            ->columns([
                TextColumn::make('trans_date')->label(__('Tax date'))->formatStateUsing(fn ($state) => Format::date($state))->sortable(),
                TextColumn::make('number')->label(__('Transaction No.'))->fontFamily('mono')->searchable(),
                TextColumn::make('serial')->label(__('Tax invoice No.'))
                    ->state(fn ($record) => $record instanceof SalesInvoice ? $record->nsfp : $record->tax_invoice_number)
                    ->placeholder('—')->fontFamily('mono'),
                Rupiah::make('dpp_total')->label(__('Tax base (DPP)')),
                Rupiah::make('tax_total')->label(__('VAT')),
                TextColumn::make('document')->label(__('Document'))->state(fn ($record) => blank(($record->customer ?? $record->vendor)?->wp_number) ? __('Aggregated') : __('Tax invoice')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn ($record) => FilingDocuments::status($record))
                    ->formatStateUsing(fn (string $state) => Format::code($state, 'filing'))
                    ->color(fn (string $state) => match ($state) {
                        'numbered' => 'success',
                        'exported' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('correction')->label(__('Correction'))->badge()->color('warning')
                    ->state(fn ($record) => FilingDocuments::isCorrection($record) ? __('Replacement') : null)->placeholder('—'),
                TextColumn::make('party_tax_id')->label(__('Tax ID'))->state(fn ($record) => ($record->customer ?? $record->vendor)?->wp_number)->placeholder('—'),
                TextColumn::make('party_name')->label(__('Name'))->state(fn ($record) => ($record->customer ?? $record->vendor)?->wp_name ?: ($record->customer ?? $record->vendor)?->name),
                TextColumn::make('info')->label(__('Info'))->state(fn ($record) => implode(' ', FilingDocuments::info($record)))->wrap()->placeholder('—')->color('warning'),
            ])
            ->recordActions([
                Action::make('clearSerial')
                    ->label(__('Clear serial'))
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record instanceof SalesInvoice && filled($record->nsfp) && static::canUpdate())
                    ->requiresConfirmation()
                    ->modalDescription(fn (SalesInvoice $record) => __('The invoice is locked while its serial :serial is recorded. Clearing it unlocks the invoice; the old serial stays in the activity log.', ['serial' => $record->nsfp]))
                    ->action(function (SalesInvoice $record): void {
                        app(TaxFilingService::class)->clearSerial($record);
                        Notification::make()->title(__('Serial cleared from :number', ['number' => $record->number]))->success()->send();
                        $this->resetTable();
                    }),
            ])
            ->selectable()
            ->bulkActions([
                BulkAction::make('export')
                    ->label(__('Export selected'))
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('primary')
                    // A file of every buyer's tax details leaving the system: the export right too.
                    ->visible(fn () => $this->kind() === TaxFiling::OUT && static::canUpdate() && HakAkses::canSpecial(HakKhusus::ExportData))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        try {
                            $filing = app(TaxFilingService::class)->export($records, $this->format(), (int) $this->filters['year'], (int) $this->filters['month'], $this->branchId());
                        } catch (\RuntimeException $e) {
                            Notification::make()->title(__('Cannot export'))->body($e->getMessage())->danger()->persistent()->send();

                            return null;
                        }
                        Notification::make()->title(__(':document_count invoice(s) written to :file_name', ['document_count' => $filing->document_count, 'file_name' => $filing->file_name]))->success()->send();

                        return response()->download(Storage::disk('local')->path($filing->file_path), $filing->file_name);
                    }),
            ])
            ->defaultSort('trans_date')
            ->emptyStateHeading(__('No tax invoices in this period'))
            ->emptyStateDescription(__('Taxable invoices dated within the chosen days appear here; pick them and export, then paste the serial numbers back.'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pasteSerials')
                ->label(__('Paste serial numbers'))
                ->icon('heroicon-m-clipboard-document')
                ->color('gray')
                ->visible(fn () => static::canUpdate())
                ->schema([
                    Textarea::make('pasted')->label(__('One per line: invoice number, then the serial'))->rows(8)->required()
                        ->helperText(__('Separated by a tab, comma, semicolon or space; e.g. INV-2611-0001  010.002-26.00000123')),
                ])
                ->action(function (array $data): void {
                    $result = app(TaxFilingService::class)->storeSerials((string) $data['pasted'], $this->kind());
                    Notification::make()->title(__(':count serial(s) stored', ['count' => count($result['stored'])]))->success()->send();
                    if ($result['unknown'] !== []) {
                        Notification::make()->title(count($result['unknown']).' line(s) not understood')
                            ->body(implode("\n", $result['unknown']))
                            ->warning()->persistent()->send();
                    }
                    $this->resetTable();
                }),
            Action::make('filings')
                ->label(__('Previous exports'))
                ->icon('heroicon-m-folder')
                ->color('gray')
                ->modalHeading(__('Previous exports'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close'))
                ->modalContent(fn () => view('filament.pages.tax.filings', [
                    'filings' => TaxFiling::query()->where('format', $this->format())->whereIn('kind', [TaxFiling::OUT, TaxFiling::IN])->latest('created_at')->limit(20)->get(),
                ])),
        ];
    }

    /** The tax office's reference codes, printed so the accountant can check them against the file. */
    public function referenceCodes(): array
    {
        $codes = (array) config('pajak.coretax', []);

        return collect($codes)
            ->filter(fn ($value) => is_scalar($value))
            ->mapWithKeys(fn ($value, string $key) => [$key => (string) $value])
            ->all();
    }

    protected function kind(): string
    {
        return ($this->filters['kind'] ?? TaxFiling::OUT) === TaxFiling::IN ? TaxFiling::IN : TaxFiling::OUT;
    }

    protected function from(): string
    {
        $month = $this->month();
        $day = min(max((int) ($this->filters['day_from'] ?? 1), 1), $month->daysInMonth);

        return $month->day($day)->toDateString();
    }

    protected function until(): string
    {
        $month = $this->month();
        $day = min(max((int) ($this->filters['day_to'] ?? 31), 1), $month->daysInMonth);

        return $month->day($day)->toDateString();
    }

    protected function branchId(): ?int
    {
        return BranchFields::reportBranch($this->filters['branch_id'] ?? null);
    }

    /** The first day of the chosen month. */
    private function month(): CarbonImmutable
    {
        $year = (int) ($this->filters['year'] ?? today()->format('Y'));
        $month = min(max((int) ($this->filters['month'] ?? today()->format('n')), 1), 12);

        return CarbonImmutable::create($year, $month, 1);
    }
}
