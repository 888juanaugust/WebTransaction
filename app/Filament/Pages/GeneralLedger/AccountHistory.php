<?php

declare(strict_types=1);

namespace App\Filament\Pages\GeneralLedger;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\MenuKey;
use App\Domain\Reports\Period;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Filament\Support\TagFields;
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
 * Account History: one account's ledger between two dates with a running
 * balance, from the journal lines of active postings.
 */
class AccountHistory extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.general-ledger.account-history';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::AccountHistory;
    }

    public function mount(): void
    {
        $this->form->fill([
            'account_id' => null,
            'from' => today()->startOfMonth()->toDateString(),
            'until' => today()->toDateString(),
            'department_id' => null,
            'project_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(3)->schema([
                    Select::make('account_id')->label(__('Account'))->options(fn () => Account::options())->searchable()->native(false)->live(),
                    DatePicker::make('from')->label(__('From'))->native(false)->live(),
                    DatePicker::make('until')->label(__('Until'))->native(false)->live(),
                    ...TagFields::filters(),
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
                TextColumn::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('source_number')->label(__('Source No.'))->fontFamily('mono'),
                TextColumn::make('source_type')->label(__('Transaction type'))->badge()->color('gray'),
                TextColumn::make('description')->label(__('fields.description'))->limit(50),
                TextColumn::make('amount')->label(__('Movement'))->alignEnd()->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('side')->label(__('Type')),
                TextColumn::make('balance')->label(__('Balance'))->alignEnd()->weight('medium')->extraCellAttributes(['class' => 'ae-money']),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Pick an account'))
            ->emptyStateDescription(__('The ledger of the account between the two dates, with its running balance.'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $accountId = $this->filters['account_id'] ?? null;
        if (! $accountId) {
            return collect();
        }
        $account = Account::query()->find($accountId);
        $debitNormal = $account?->account_type->isDebitNormal() ?? true;
        $from = $this->filters['from'] ?? null;
        $until = $this->filters['until'] ?? null;
        $tags = new Period($from ?? today()->toDateString(), $until ?? today()->toDateString(), null,
            TagFields::picked($this->filters['department_id'] ?? null, 'departments'),
            TagFields::picked($this->filters['project_id'] ?? null, 'projects'));

        // Only the lines of the user's branches (and lines of no branch).
        $opening = (int) $tags->applyTo(BranchLimit::apply(JournalLine::query()->active(), auth()->user()))
            ->where('account_id', $accountId)
            ->when($from, fn ($q) => $q->where('trans_date', '<', $from))
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')
            ->value('net');
        $balance = $debitNormal ? $opening : -$opening;

        $rows = [[
            'id' => 0,
            'trans_date' => $from ? Format::date($from) : '',
            'source_number' => '',
            'source_type' => '',
            'description' => 'Opening balance',
            'amount' => '',
            'side' => '',
            'balance' => Format::number($balance),
        ]];

        $lines = $tags->applyTo(BranchLimit::apply(JournalLine::query()->active(), auth()->user()))
            ->with('entry')
            ->where('account_id', $accountId)
            ->when($from, fn ($q) => $q->where('trans_date', '>=', $from))
            ->when($until, fn ($q) => $q->where('trans_date', '<=', $until))
            ->orderBy('trans_date')->orderBy('id')
            ->get();

        foreach ($lines as $line) {
            $net = $line->debit - $line->credit;
            $balance += $debitNormal ? $net : -$net;
            $rows[] = [
                'id' => $line->id,
                'trans_date' => Format::date($line->trans_date),
                'source_number' => $line->entry?->source_number,
                'source_type' => Format::documentType($line->entry?->source_type),
                'description' => $line->memo ?: $line->entry?->description,
                'amount' => Format::number($line->debit ?: $line->credit),
                'side' => $line->debit ? 'Debit' : 'Credit',
                'balance' => Format::number($balance),
            ];
        }

        return collect($rows);
    }
}
