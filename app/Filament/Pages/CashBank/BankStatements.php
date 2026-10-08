<?php

declare(strict_types=1);

namespace App\Filament\Pages\CashBank;

use App\Domain\Access\MenuKey;
use App\Domain\CashBank\StatementImporter;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Models\CashBank\BankStatementLine;
use App\Models\GeneralLedger\Account;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Bank Statements: the bank's own movements, imported from its CSV export,
 * the basis of reconciliation.
 */
class BankStatements extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.cash-bank.bank-statements';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::BankStatements;
    }

    public function mount(): void
    {
        $this->form->fill([
            'bank_account_id' => null,
            'from' => today()->startOfMonth()->toDateString(),
            'until' => today()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(3)->schema([
                    Select::make('bank_account_id')->label(__('Bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->native(false)->live(),
                    DatePicker::make('from')->label(__('From'))->native(false)->live(),
                    DatePicker::make('until')->label(__('Until'))->native(false)->live(),
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
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('trans_date')->label(__('Date')),
                TextColumn::make('description')->label(__('Description'))->limit(60),
                TextColumn::make('amount')->label(__('Movement'))->alignEnd()->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('side')->label(__('Type')),
                TextColumn::make('balance')->label(__('Balance'))->alignEnd()->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('matched')->label(__('Matched'))->alignCenter(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Pick a bank'))
            ->emptyStateDescription(__('Import the bank\'s CSV export, then match it against the book on the Bank Reconciliation screen.'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(__('Import statement'))
                ->icon('heroicon-m-arrow-up-tray')
                ->color('primary')
                ->visible(fn () => static::canUpdate())
                ->schema([
                    Select::make('bank_account_id')->label(__('Bank'))
                        ->options(fn () => Account::options(AccountType::CashBank))
                        ->searchable()->native(false)->required()
                        ->default(fn () => $this->filters['bank_account_id'] ?? null),
                    FileUpload::make('file')->label(__('CSV or Excel file'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->disk('local')
                        ->directory('bank-statements')
                        ->required()
                        ->storeFileNamesIn('file_name')
                        ->helperText(__('A header row with a date column, a description, and an amount column (or debit and credit columns); a balance and a reference when the bank gives them.')),
                ])
                ->action(function (array $data): void {
                    try {
                        $path = Storage::disk('local')->path((string) $data['file']);
                        $statement = app(StatementImporter::class)->import((int) $data['bank_account_id'], $path, $data['file_name'] ?? null);
                    } catch (\RuntimeException $e) {
                        Notification::make()->title(__('Cannot import'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete((string) $data['file']); // the lines are kept; the bank's file is not
                    }
                    Notification::make()->title(__(':line_count lines imported from :source_file_name', ['line_count' => $statement->line_count, 'source_file_name' => $statement->source_file_name]))->success()->send();
                    $this->filters['bank_account_id'] = $statement->bank_account_id;
                    $this->filters['from'] = $statement->from_date?->toDateString();
                    $this->filters['until'] = $statement->to_date?->toDateString();
                    $this->resetTable();
                }),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $bankAccountId = $this->filters['bank_account_id'] ?? null;
        if (! $bankAccountId) {
            return collect();
        }
        $from = $this->filters['from'] ?? null;
        $until = $this->filters['until'] ?? null;

        return BankStatementLine::query()
            ->with('reconciliationItem')
            ->where('bank_account_id', $bankAccountId)
            ->when($from, fn ($query) => $query->where('trans_date', '>=', $from))
            ->when($until, fn ($query) => $query->where('trans_date', '<=', $until))
            ->orderBy('trans_date')->orderBy('id')
            ->get()
            ->map(fn (BankStatementLine $line) => [
                'id' => $line->id,
                'trans_date' => Format::date($line->trans_date),
                'description' => $line->description,
                'amount' => Format::number(abs($line->amount)),
                'side' => $line->amount >= 0 ? 'In' : 'Out',
                'balance' => $line->balance === null ? '' : Format::number($line->balance),
                'matched' => $line->reconciliationItem ? '✓' : '',
            ])->values();
    }
}
