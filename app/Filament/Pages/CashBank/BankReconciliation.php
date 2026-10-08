<?php

declare(strict_types=1);

namespace App\Filament\Pages\CashBank;

use App\Domain\Access\MenuKey;
use App\Domain\CashBank\Reconciler;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Filament\Support\MoneyInput;
use App\Models\CashBank\BankReconciliation as Reconciliation;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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

/**
 * Bank Reconciliation: the book lines of one bank account over a period,
 * cleared by hand or matched against the imported statement; closes when the
 * cleared balance meets the statement's ending balance. A cleared line locks
 * its document.
 */
class BankReconciliation extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.cash-bank.bank-reconciliation';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    public ?array $filters = [];

    private ?Reconciliation $reconciliation = null;

    private bool $reconciliationResolved = false;

    public static function menuKey(): MenuKey
    {
        return MenuKey::BankReconciliation;
    }

    public function mount(): void
    {
        $this->form->fill([
            'bank_account_id' => null,
            'start' => today()->startOfMonth()->toDateString(),
            'end' => today()->toDateString(),
            'statement_balance' => 0,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(4)->schema([
                    Select::make('bank_account_id')->label(__('Bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->native(false)->live(),
                    DatePicker::make('start')->label(__('Period from'))->native(false)->live(),
                    DatePicker::make('end')->label(__('Period until'))->native(false)->live(),
                    MoneyInput::make('statement_balance')->label(__('Statement ending balance'))->default(0)->live(onBlur: true),
                ]),
            ])
            ->statePath('filters');
    }

    public function updatedFilters(mixed $value = null, ?string $key = null): void
    {
        if ($key !== 'statement_balance') {
            $this->recallStatementBalance();
        }
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('trans_date')->label(__('Date')),
                TextColumn::make('source_number')->label(__('Source No.'))->fontFamily('mono'),
                TextColumn::make('source_type')->label(__('Transaction type'))->badge()->color('gray'),
                TextColumn::make('description')->label(__('Description'))->limit(50),
                TextColumn::make('debit')->label(__('Debit'))->alignEnd()->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('credit')->label(__('Credit'))->alignEnd()->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('cleared')->label(__('Cleared'))->alignCenter(),
            ])
            ->recordActions([
                Action::make('clear')->label(__('Clear'))->icon('heroicon-m-check')
                    ->visible(fn (array $record) => $record['cleared'] === '' && $this->isOpen() && static::canUpdate())
                    ->action(function (array $record): void {
                        $reconciliation = $this->reconciliation();
                        if ($reconciliation === null) {
                            return;
                        }
                        try {
                            app(Reconciler::class)->clear($reconciliation, [(int) $record['id']]);
                        } catch (\RuntimeException $e) {
                            Notification::make()->title(__('Cannot clear'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                        $this->resetTable();
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Pick a bank and a period'))
            ->emptyStateDescription(__('The book lines of the bank up to the period\'s end that the bank has not yet agreed with, and those cleared in this reconciliation.'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('autoMatch')
                ->label(__('Match against statement'))
                ->icon('heroicon-m-sparkles')
                ->color('gray')
                ->visible(fn () => $this->isOpen() && static::canUpdate())
                ->action(function (): void {
                    $reconciliation = $this->reconciliation();
                    if ($reconciliation === null) {
                        return;
                    }
                    try {
                        $matched = app(Reconciler::class)->autoMatch($reconciliation);
                        Notification::make()->title(__(':matched line(s) matched', ['matched' => $matched]))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title(__('Cannot match'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                    $this->resetTable();
                }),
            Action::make('clearAll')
                ->label(__('Clear every line shown'))
                ->icon('heroicon-m-check')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Every line in the list not yet cleared is marked as agreed by the bank.'))
                ->visible(fn () => $this->isOpen() && static::canUpdate())
                ->action(function (): void {
                    $reconciliation = $this->reconciliation();
                    if ($reconciliation === null) {
                        return;
                    }
                    $ids = $this->rows()->filter(fn (array $row) => $row['cleared'] === '')->map(fn (array $row) => (int) $row['id'])->values()->all();
                    try {
                        $cleared = app(Reconciler::class)->clear($reconciliation, $ids);
                        Notification::make()->title(__(':cleared line(s) cleared', ['cleared' => $cleared]))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title(__('Cannot clear'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                    $this->resetTable();
                }),
            Action::make('close')
                ->label(__('Close reconciliation'))
                ->icon('heroicon-m-lock-closed')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn () => ($summary = $this->summary()) === null
                    ? ''
                    : "Difference between the statement's ending balance and the cleared balance: {$summary['difference']}. Closing locks every cleared line's document.")
                ->visible(fn () => $this->isOpen() && static::canUpdate())
                ->action(function (): void {
                    $reconciliation = $this->reconciliation();
                    if ($reconciliation === null) {
                        return;
                    }
                    try {
                        app(Reconciler::class)->close($reconciliation);
                        Notification::make()->title(__('Reconciliation closed'))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title(__('Cannot close'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                    $this->resetTable();
                }),
        ];
    }

    /**
     * The reconciliation's figures as they read on screen, or null before a
     * bank and a period are picked.
     *
     * @return array{book_balance: string, cleared_balance: string, uncleared: string, statement_balance: string, difference: string, balanced: bool, status: string}|null
     */
    public function summary(): ?array
    {
        $reconciliation = $this->reconciliation();
        if ($reconciliation === null) {
            return null;
        }
        $summary = app(Reconciler::class)->summary($reconciliation);

        return [
            'book_balance' => Format::rupiah($summary['book_balance']),
            'cleared_balance' => Format::rupiah($summary['cleared_balance']),
            'uncleared' => Format::rupiah($summary['uncleared']),
            'statement_balance' => Format::rupiah($summary['statement_balance']),
            'difference' => Format::rupiah($summary['difference']),
            'balanced' => $summary['difference'] === 0,
            'status' => $reconciliation->isClosed() ? __('Closed on :date', ['date' => Format::date($reconciliation->closed_at)]) : __('Open'),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $reconciliation = $this->reconciliation();
        if ($reconciliation === null) {
            return collect();
        }

        return app(Reconciler::class)->bookLines($reconciliation)
            ->load('reconciliationItem')
            ->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'trans_date' => Format::date($line->trans_date),
                'source_number' => $line->entry?->source_number,
                'source_type' => Format::documentType($line->entry?->source_type),
                'description' => $line->memo ?: $line->entry?->description,
                'debit' => $line->debit ? Format::number($line->debit) : '',
                'credit' => $line->credit ? Format::number($line->credit) : '',
                'cleared' => $line->reconciliationItem ? '✓' : '',
            ])->values();
    }

    /** The reconciliation of the chosen bank and period, opened on first use; null until all three are set. */
    private function reconciliation(): ?Reconciliation
    {
        if ($this->reconciliationResolved) {
            return $this->reconciliation;
        }
        $this->reconciliationResolved = true;

        $bankAccountId = $this->filters['bank_account_id'] ?? null;
        $start = $this->filters['start'] ?? null;
        $end = $this->filters['end'] ?? null;
        if (! $bankAccountId || ! $start || ! $end) {
            return $this->reconciliation = null;
        }
        try {
            return $this->reconciliation = app(Reconciler::class)->open((int) $bankAccountId, $start, $end, (int) ($this->filters['statement_balance'] ?? 0));
        } catch (\RuntimeException) {
            return $this->reconciliation = null;
        }
    }

    private function isOpen(): bool
    {
        $reconciliation = $this->reconciliation();

        return $reconciliation !== null && ! $reconciliation->isClosed();
    }

    /** Coming back to a period already reconciled, show its stored statement balance instead of overwriting it with the input's default. */
    private function recallStatementBalance(): void
    {
        $bankAccountId = $this->filters['bank_account_id'] ?? null;
        $start = $this->filters['start'] ?? null;
        $end = $this->filters['end'] ?? null;
        if (! $bankAccountId || ! $start || ! $end) {
            return;
        }
        $existing = Reconciliation::query()
            ->where('bank_account_id', (int) $bankAccountId)
            ->where('start_date', $start)
            ->where('end_date', $end)
            ->first();
        if ($existing !== null) {
            $this->filters['statement_balance'] = $existing->statement_balance;
        }
    }
}
