<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

use App\Models\Company\Branch;
use App\Models\Settings\DocumentSeries;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Draws the next number of a series for a date. The counter advances in one
 * INSERT … ON CONFLICT … RETURNING statement, so concurrent saves never share
 * a number; call it inside the transaction that saves the document, and the
 * number is released with a rollback like everything else in it. A series
 * whose format carries the branch code counts each branch alone.
 */
final class NumberGenerator
{
    /** @param  string|null  $branch  the document's branch code, needed when the format carries it */
    public function next(DocumentSeries $series, CarbonInterface $date, ?string $branch = null): string
    {
        $pattern = $series->pattern();
        $branch = $this->branchCode($series, $branch);
        $row = DB::selectOne(
            <<<'SQL'
                INSERT INTO document_counters (document_series_id, period_key, last_value)
                VALUES (?, ?, 1)
                ON CONFLICT (document_series_id, period_key)
                DO UPDATE SET last_value = document_counters.last_value + 1
                RETURNING last_value
            SQL,
            [$series->id, $this->periodKey($series, $date, $branch)],
        );

        return $pattern->render($date, (int) $row->last_value, $series->counter_digits, $branch);
    }

    /** The number the next save would get, without consuming it. */
    public function preview(DocumentSeries $series, CarbonInterface $date, ?string $branch = null): string
    {
        $branch = $this->branchCode($series, $branch);
        $last = (int) DB::table('document_counters')
            ->where('document_series_id', $series->id)
            ->where('period_key', $this->periodKey($series, $date, $branch))
            ->value('last_value');

        return $series->pattern()->render($date, $last + 1, $series->counter_digits, $branch);
    }

    /** The counter row: the reset period, prefixed with the branch code when the format carries one. */
    private function periodKey(DocumentSeries $series, CarbonInterface $date, ?string $branch): string
    {
        $key = $series->reset_rule->periodKey($date);

        return $series->pattern()->hasBranch() ? "{$branch}:{$key}" : $key;
    }

    /** The code the number carries: the document's branch, else the default branch (a document tagged to no branch is numbered under it). */
    private function branchCode(DocumentSeries $series, ?string $branch): ?string
    {
        if (! $series->pattern()->hasBranch()) {
            return null;
        }
        $code = $branch ?: Branch::default()?->code;
        if ($code === null || $code === '') {
            throw new InvalidArgumentException(__('This number format carries the branch code; the document needs a branch with a code.'));
        }

        return $code;
    }

    /** The series a user may pick for a transaction type, the default first. */
    public function seriesFor(TransactionType $type, ?User $user = null): Collection
    {
        return DocumentSeries::query()
            ->where('transaction_type', $type)
            ->where('is_active', true)
            ->when($user && ! $user->isAdministrator(), fn ($q) => $q->where(fn ($q) => $q
                ->where('used_all_user', true)
                ->orWhereHas('users', fn ($u) => $u->whereKey($user->id))))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function defaultSeries(TransactionType $type, ?User $user = null): ?DocumentSeries
    {
        return $this->seriesFor($type, $user)->first();
    }
}
