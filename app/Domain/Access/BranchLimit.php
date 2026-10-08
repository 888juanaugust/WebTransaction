<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\Company\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * A user limited to some branches sees only the records of those branches,
 * and the records tagged to no branch. Applies to any table with a
 * branch_id column; a user no branch is closed to sees everything.
 */
final class BranchLimit
{
    /** @var array<string, bool> table → has a branch_id column */
    private static array $branched = [];

    public static function apply(Builder $query, ?User $user): Builder
    {
        $table = $query->getModel()->getTable();
        if (! self::isBranched($table)) {
            return $query;
        }
        $limits = Branch::limitsOf($user);
        if ($limits === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereNull("{$table}.branch_id")->orWhereIn("{$table}.branch_id", $limits));
    }

    /** Whether the user may tag a record to the branch (no branch is always allowed). */
    public static function allows(?User $user, ?int $branchId): bool
    {
        if ($branchId === null) {
            return true;
        }
        $limits = Branch::limitsOf($user);

        return $limits === null || in_array($branchId, $limits, true);
    }

    public static function isBranched(string $table): bool
    {
        return self::$branched[$table] ??= Schema::hasColumn($table, 'branch_id');
    }
}
