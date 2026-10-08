<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\Ledger;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * General Ledger (R-04): account by account, the opening balance, every
 * posting of the period with its running balance, and the closing balance.
 */
class GeneralLedgerReport extends ReportPage
{
    protected function usesTags(): bool
    {
        return true;
    }

    public static function reportKey(): string
    {
        return 'general-ledger';
    }

    public static function title(): string
    {
        return __('General Ledger');
    }

    public static function group(): string
    {
        return 'Financial';
    }

    public static function description(): string
    {
        return __('Every posting of every account in the period, with running balances.');
    }

    protected function defaultFilters(): array
    {
        return array_merge(parent::defaultFilters(), ['account_id' => null]);
    }

    protected function extraFilters(): array
    {
        return [
            Select::make('account_id')->label(__('Account'))->options(fn () => Account::options())->placeholder(__('All accounts'))->searchable()->native(false)->nullable()->live(),
        ];
    }

    protected function rows(): array
    {
        $period = $this->period();
        $accountId = isset($this->filters['account_id']) && $this->filters['account_id'] !== '' ? (int) $this->filters['account_id'] : null;
        $opening = Ledger::openingNet($period);

        $lines = $period->applyTo(JournalLine::query()->active())
            ->with('entry')
            ->when($accountId, fn (Builder $query) => $query->where('journal_lines.account_id', $accountId))
            ->whereBetween('journal_lines.trans_date', [$period->fromDate(), $period->untilDate()])
            ->orderBy('journal_lines.trans_date')->orderBy('journal_lines.id')
            ->get()
            ->groupBy('account_id');

        $rows = [];
        $accounts = Account::query()->orderBy('no')->when($accountId, fn (Builder $query) => $query->whereKey($accountId))->get();
        foreach ($accounts as $account) {
            $openingNet = $opening[$account->id] ?? 0;
            $accountLines = $lines->get($account->id, collect());
            if ($openingNet === 0 && $accountLines->isEmpty()) {
                continue;
            }
            $balance = Ledger::normal($account, $openingNet);
            $rows[] = ['id' => "h-{$account->id}", 'trans_date' => null, 'source' => '', 'description' => '', 'name' => "{$account->no} {$account->name}", 'debit' => null, 'credit' => null, 'balance' => null, 'is_heading' => true];
            $rows[] = ['id' => "o-{$account->id}", 'trans_date' => $period->fromDate(), 'source' => '', 'description' => 'Opening balance', 'debit' => null, 'credit' => null, 'balance' => $balance];
            foreach ($accountLines as $line) {
                $balance += Ledger::normal($account, $line->debit - $line->credit);
                $rows[] = [
                    'id' => "l-{$line->id}",
                    'trans_date' => $line->trans_date,
                    'source' => $line->entry?->source_number,
                    'description' => $line->memo ?: $line->entry?->description,
                    'debit' => $line->debit ?: null,
                    'credit' => $line->credit ?: null,
                    'balance' => $balance,
                ];
            }
            $rows[] = ['id' => "c-{$account->id}", 'trans_date' => $period->untilDate(), 'source' => '', 'description' => 'Closing balance', 'debit' => $accountLines->sum('debit'), 'credit' => $accountLines->sum('credit'), 'balance' => $balance, 'is_total' => true];
        }

        return $rows;
    }

    protected function columns(): array
    {
        return [
            static::date('trans_date', __('Date')),
            static::text('source', __('Source No.'))->fontFamily('mono'),
            static::text('description', __('Description'))->state(fn ($record) => ($record['is_heading'] ?? false) ? $record['name'] : $record['description'])->limit(60),
            static::money('debit', __('Debit')),
            static::money('credit', __('Credit')),
            static::money('balance', __('Balance')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Date'), __('Source No.'), __('Description'), __('Debit'), __('Credit'), __('Balance')];
    }

    protected function exportRow(array $row): array
    {
        if ($row['is_heading'] ?? false) {
            return [null, null, $row['name'], null, null, null];
        }

        return [$row['trans_date'], $row['source'], $row['description'], $row['debit'], $row['credit'], $row['balance']];
    }
}
