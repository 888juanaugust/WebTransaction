<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Models\Company\Department;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** The dates a report runs over, with a branch, a department (and the departments under it) and a project when asked. */
final class Period
{
    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $until;

    public function __construct(
        CarbonImmutable|string $from,
        CarbonImmutable|string $until,
        public readonly ?int $branchId = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $projectId = null,
    ) {
        $this->from = CarbonImmutable::parse($from)->startOfDay();
        $this->until = CarbonImmutable::parse($until)->startOfDay();
    }

    public static function month(int $year, int $month, ?int $branchId = null): self
    {
        $start = CarbonImmutable::create($year, $month, 1);

        return new self($start, $start->endOfMonth(), $branchId);
    }

    /** The same filters over other dates. */
    public function withDates(CarbonImmutable|string $from, CarbonImmutable|string $until): self
    {
        return new self($from, $until, $this->branchId, $this->departmentId, $this->projectId);
    }

    /** Narrows a query on journal lines to the branch, department (with the departments under it) and project asked for. */
    public function applyTo(Builder $query, string $table = 'journal_lines'): Builder
    {
        return $query
            ->when($this->branchId, fn (Builder $q) => $q->where("{$table}.branch_id", $this->branchId))
            ->when($this->departmentId, fn (Builder $q) => $q->whereIn("{$table}.department_id", Department::withDescendants($this->departmentId)))
            ->when($this->projectId, fn (Builder $q) => $q->where("{$table}.project_id", $this->projectId));
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function untilDate(): string
    {
        return $this->until->toDateString();
    }

    public function label(): string
    {
        return $this->from->format('d M Y').' – '.$this->until->format('d M Y');
    }
}
