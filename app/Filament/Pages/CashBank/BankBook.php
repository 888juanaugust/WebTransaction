<?php

declare(strict_types=1);

namespace App\Filament\Pages\CashBank;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\MenuKey;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
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

/**
 * Bank Book: one cash/bank account's movements between two dates with its
 * running balance, the cheque number and the document behind each line.
 */
class BankBook extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.cash-bank.bank-book';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::BankBook;
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
                    Select::make('bank_account_id')->label(__('Cash / Bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->native(false)->live(),
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
                TextColumn::make('source_number')->label(__('Source No.'))->fontFamily('mono'),
                TextColumn::make('cheque_no')->label(__('Cheque No.'))->fontFamily('mono'),
                TextColumn::make('source_type')->label(__('Transaction type'))->badge()->color('gray'),
                TextColumn::make('description')->label(__('Description'))->limit(50),
                TextColumn::make('amount')->label(__('Movement'))->alignEnd()->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('side')->label(__('Type')),
                TextColumn::make('balance')->label(__('Balance'))->alignEnd()->weight('medium')->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('reconciled')->label(__('Reconciled'))->alignCenter(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Pick a cash or bank account'))
            ->emptyStateDescription(__('Its movements between the two dates with the running balance, the cheque number and the document behind each line.'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $accountId = $this->filters['bank_account_id'] ?? null;
        // A cash or bank account only, whatever id the filter was given.
        if ($accountId && ! Account::query()->whereKey($accountId)->ofType(AccountType::CashBank)->exists()) {
            $accountId = null;
        }
        if (! $accountId) {
            return collect();
        }
        $from = $this->filters['from'] ?? null;
        $until = $this->filters['until'] ?? null;

        // Cash and bank accounts are debit-normal: money in adds to the balance.
        // Only the lines of the user's branches (and lines of no branch).
        $balance = (int) BranchLimit::apply(JournalLine::query()->active(), auth()->user())
            ->where('account_id', $accountId)
            ->when($from, fn ($query) => $query->where('trans_date', '<', $from))
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')
            ->value('net');

        $rows = [[
            'id' => 0,
            'trans_date' => $from ? Format::date($from) : '',
            'source_number' => '',
            'cheque_no' => '',
            'source_type' => '',
            'description' => __('Opening balance'),
            'amount' => '',
            'side' => '',
            'balance' => Format::number($balance),
            'reconciled' => '',
        ]];

        $lines = BranchLimit::apply(JournalLine::query()->active(), auth()->user())
            ->with(['entry', 'posting.document', 'reconciliationItem'])
            ->where('account_id', $accountId)
            ->when($from, fn ($query) => $query->where('trans_date', '>=', $from))
            ->when($until, fn ($query) => $query->where('trans_date', '<=', $until))
            ->orderBy('trans_date')->orderBy('id')
            ->get();

        foreach ($lines as $line) {
            $balance += $line->debit - $line->credit;
            $rows[] = [
                'id' => $line->id,
                'trans_date' => Format::date($line->trans_date),
                'source_number' => $line->entry?->source_number,
                'cheque_no' => $this->chequeNo($line),
                'source_type' => Format::documentType($line->entry?->source_type),
                'description' => $line->memo ?: $line->entry?->description,
                'amount' => Format::number($line->debit ?: $line->credit),
                'side' => $line->debit ? 'Debit' : 'Credit',
                'balance' => Format::number($balance),
                'reconciled' => $line->reconciliationItem ? '✓' : '',
            ];
        }

        return collect($rows);
    }

    /** The cheque number of the document behind the line; not every document carries one. */
    private function chequeNo(JournalLine $line): string
    {
        $document = $line->posting?->document;
        if ($document === null || ! array_key_exists('cheque_no', $document->getAttributes())) {
            return '';
        }

        return (string) ($document->getAttribute('cheque_no') ?? '');
    }
}
