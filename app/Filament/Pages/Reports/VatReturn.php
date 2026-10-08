<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Company\CompanyIdentity;
use App\Domain\Reports\ExcelExport;
use App\Domain\Shared\Enums\TaxDocumentCode;
use App\Domain\Shared\Format;
use App\Domain\Tax\FilingDocuments;
use App\Domain\Tax\TaxFilingService;
use App\Filament\Support\BranchFields;
use App\Filament\Support\ErpPage;
use App\Models\Tax\TaxFiling;
use App\Models\Tax\VatReturnRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** VAT Return: the period's VAT out and VAT in, document by document (down payments included), totalled for the return. */
class VatReturn extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected string $view = 'filament.pages.reports.vat-return';

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::VATReturn;
    }

    /** The company's VAT identity heads the return. */
    public function getSubheading(): ?string
    {
        return app(CompanyIdentity::class)->taxIdentityLine() ?: null;
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => today()->startOfMonth()->toDateString(),
            'until' => today()->toDateString(),
            'kind' => TaxFiling::OUT,
            'document_code' => null,
            'search' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(5)->schema([
                    DatePicker::make('from')->label(__('From'))->native(false)->live(),
                    DatePicker::make('until')->label(__('Until'))->native(false)->live(),
                    Select::make('kind')->label(__('Show'))
                        ->options([TaxFiling::OUT => __('VAT out (sales)'), TaxFiling::IN => __('VAT in (purchases)'), 'both' => __('Both')])
                        ->default(TaxFiling::OUT)->native(false)->selectablePlaceholder(false)->live(),
                    Select::make('document_code')->label(__('Document kind'))
                        ->options(TaxDocumentCode::options(TaxDocumentCode::forCustomers()))
                        ->placeholder(__('All kinds'))->nullable()->native(false)->live(),
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
            ->records(fn () => collect($this->rows()))
            ->columns([
                TextColumn::make('kind')->label(__('Tax')),
                TextColumn::make('serial')->label(__('Tax invoice No.'))->fontFamily('mono'),
                TextColumn::make('number')->label(__('Transaction No.'))->fontFamily('mono'),
                TextColumn::make('trans_date')->label(__('Date'))->formatStateUsing(fn ($state): string => $state ? Format::date($state) : ''),
                TextColumn::make('document')->label(__('Document kind')),
                TextColumn::make('description')->label(__('Description'))->limit(40),
                self::money('dpp', __('Tax base (DPP)')),
                self::money('tax', __('VAT')),
                TextColumn::make('party')->label(__('Customer / Vendor')),
            ])
            ->paginated(false)
            ->recordClasses(fn (array $record): ?string => ($record['is_total'] ?? false) ? 'ae-report-total' : null)
            ->emptyStateHeading(__('Nothing in this period'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveReturn')
                ->label(__('Save return'))
                ->icon('heroicon-m-document-check')
                ->visible(fn () => static::canUpdate())
                ->requiresConfirmation()
                ->modalDescription(fn () => __('Saves the VAT return for :from – :until with a number from the VAT return series.', ['from' => Format::date($this->from()), 'until' => Format::date($this->until())]))
                ->schema([Textarea::make('notes')->label(__('Notes'))->rows(2)])
                ->action(function (array $data): void {
                    try {
                        $return = app(TaxFilingService::class)->saveReturn($this->from(), $this->until(), $data['notes'] ?? null);
                    } catch (\RuntimeException $e) {
                        Notification::make()->title(__('Cannot save'))->body($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title(__('VAT return :number saved: :amount payable', ['number' => $return->number, 'amount' => Format::money($return->payable)]))->success()->send();
                }),
            Action::make('savedReturns')
                ->label(__('Saved returns'))
                ->icon('heroicon-m-folder')
                ->color('gray')
                ->modalHeading(__('Saved VAT returns'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close'))
                ->modalContent(fn () => view('filament.pages.reports.vat-returns-saved', ['returns' => VatReturnRecord::query()->latest('id')->limit(24)->get()])),
            Action::make('export')
                ->label(__('Export to Excel'))
                ->visible(fn () => HakAkses::canSpecial(HakKhusus::ExportData))
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn (): BinaryFileResponse => ExcelExport::download(
                    'VAT Return',
                    trim(Format::date($this->from()).' – '.Format::date($this->until()).'   '.app(CompanyIdentity::class)->taxIdentityLine()),
                    ['Tax', 'Tax invoice No.', 'Transaction No.', 'Date', 'Document kind', 'Description', 'Tax base (DPP)', 'VAT', 'Customer / Vendor'],
                    array_map(fn (array $row) => [$row['kind'], $row['serial'], $row['number'], $row['trans_date'], $row['document'], $row['description'], $row['dpp'], $row['tax'], $row['party']], $this->rows()),
                )),
        ];
    }

    /** @return list<array<string, mixed>> the documents of each kind asked for, then their totals */
    protected function rows(): array
    {
        $kind = $this->filters['kind'] ?? TaxFiling::OUT;
        $kinds = $kind === 'both' ? [TaxFiling::OUT, TaxFiling::IN] : [$kind];
        $documentCode = $this->filters['document_code'] ?? null;
        $search = $this->filters['search'] ?? null;

        $rows = [];
        $totals = [];
        foreach ($kinds as $k) {
            $totals[$k] = ['dpp' => 0, 'tax' => 0];
            foreach (FilingDocuments::vatDocuments($k, $this->from(), $this->until(), BranchFields::reportBranch(null), $search ?: null) as $entry) {
                $document = $entry['document'];
                $code = $entry['party']?->document_code;
                $code = $code instanceof TaxDocumentCode ? $code : TaxDocumentCode::tryFrom((string) $code);
                if ($documentCode && $code?->value !== $documentCode) {
                    continue;
                }
                $rows[] = [
                    'id' => $k.'-'.$document->getMorphClass().'-'.$document->id,
                    'kind' => $k === TaxFiling::IN ? __('VAT in') : __('VAT out'),
                    'serial' => $entry['serial'],
                    'number' => $document->number,
                    'trans_date' => $document->trans_date,
                    'document' => $entry['down_payment'] ? __('Down payment') : $code?->getLabel(),
                    'description' => $document->description,
                    'dpp' => $entry['dpp'],
                    'tax' => $entry['tax'],
                    'party' => $entry['party']?->name,
                ];
                $totals[$k]['dpp'] += $entry['dpp'];
                $totals[$k]['tax'] += $entry['tax'];
            }
        }

        foreach ($totals as $k => $sum) {
            $rows[] = $this->summaryRow("t-{$k}", $k === TaxFiling::IN ? __('Total VAT in') : __('Total VAT out'), $sum['dpp'], $sum['tax']);
        }
        if (count($kinds) === 2) {
            $rows[] = $this->summaryRow('t-payable', __('VAT payable (out − in)'), null, $totals[TaxFiling::OUT]['tax'] - $totals[TaxFiling::IN]['tax']);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function summaryRow(string $id, string $label, ?int $dpp, int $tax): array
    {
        return ['id' => $id, 'kind' => '', 'serial' => '', 'number' => '', 'trans_date' => null, 'document' => '', 'description' => $label, 'dpp' => $dpp, 'tax' => $tax, 'party' => '', 'is_total' => true];
    }

    private function from(): string
    {
        return $this->filters['from'] ?? today()->startOfMonth()->toDateString();
    }

    private function until(): string
    {
        return $this->filters['until'] ?? today()->toDateString();
    }

    /** As ReportPage::money formats an amount: thousands separated, blank when empty. */
    private static function money(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->alignEnd()
            ->formatStateUsing(fn ($state): string => $state === '' || $state === null ? '' : Format::number((int) $state))
            ->extraCellAttributes(['class' => 'ae-money']);
    }
}
