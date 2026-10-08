<?php

declare(strict_types=1);

namespace App\Filament\Pages\Budgeting;

use App\Domain\Access\MenuKey;
use App\Domain\Budgeting\BudgetMonitor as Monitor;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Support\BranchFields;
use App\Filament\Support\ErpPage;
use App\Filament\Support\Months;
use App\Models\GeneralLedger\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Budget Monitor: budget against actual per income and expense account for a
 * year or one of its months, the actual drawn from the journal.
 */
class BudgetMonitor extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.budgeting.budget-monitor';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    public ?array $filters = [];

    public static function menuKey(): MenuKey
    {
        return MenuKey::BudgetMonitor;
    }

    public function mount(): void
    {
        $this->form->fill([
            'year' => today()->year,
            'month' => null,
            'account_id' => null,
            'branch_id' => BranchFields::reportBranch(null),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(4)->schema([
                    TextInput::make('year')->label(__('Year'))->numeric()->minValue(2000)->maxValue(2100)->default(today()->year)->live(),
                    Select::make('month')->label(__('Month'))->options(Months::options())->placeholder(__('Whole year'))->nullable()->native(false)->live(),
                    Select::make('account_id')->label(__('Account'))
                        ->options(fn () => Account::options(AccountType::Revenue, AccountType::CostOfSales, AccountType::Expense, AccountType::OtherIncome, AccountType::OtherExpense))
                        ->placeholder(__('All accounts'))->nullable()->searchable()->native(false)->live(),
                    BranchFields::filter(),
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
                TextColumn::make('no')->label(__('No.'))->fontFamily('mono'),
                TextColumn::make('name')->label(__('Account')),
                self::money('budget', __('Budget')),
                self::money('actual', __('Used')),
                self::money('remaining', __('Remaining')),
                TextColumn::make('used_percent')->label(__('Used %'))->alignEnd()
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : Format::quantity($state, 1).' %')
                    ->color(fn ($state): ?string => match (true) {
                        $state === null => null,
                        (float) $state > 100 => 'danger',
                        (float) $state > 90 => 'warning',
                        default => null,
                    }),
            ])
            ->recordClasses(fn (array $record): ?string => ($record['is_total'] ?? false) ? 'ae-report-total' : null)
            ->paginated(false)
            ->emptyStateHeading(__('No budget and no movement'))
            ->emptyStateDescription(__('Set a budget for the period, or pick a year and month that have postings.'));
    }

    private static function money(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->alignEnd()
            ->formatStateUsing(fn ($state): string => Format::number((int) $state))
            ->extraCellAttributes(['class' => 'ae-money']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $year = (int) ($this->filters['year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            return collect();
        }

        $month = $this->filters['month'] ?? null;
        $accountId = $this->filters['account_id'] ?? null;
        $branchId = BranchFields::reportBranch($this->filters['branch_id'] ?? null);

        $rows = Monitor::rows($year, $month ? (int) $month : null, $accountId ? (int) $accountId : null, $branchId);

        // The total alone means nothing was budgeted or spent: show the empty state instead.
        if (count($rows) === 1) {
            return collect();
        }

        return collect($rows);
    }
}
