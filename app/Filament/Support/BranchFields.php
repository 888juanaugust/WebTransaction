<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Company\Branch;
use Filament\Forms\Components\Select;

/**
 * The branch pickers. On a record: only the branches the user may work in,
 * starting at their default one. On a report or filter: "All branches" only
 * for a user no branch is closed to; a limited user picks one of theirs.
 */
final class BranchFields
{
    /** @param  bool  $defaulted  start new records in the user's default branch; otherwise only a limited user's start in one */
    public static function select(?string $label = null, bool $defaulted = true): Select
    {
        return Select::make('branch_id')
            ->label($label ?? __('fields.branch'))
            ->options(fn ($state) => self::options($state))
            ->default(fn () => $defaulted || Branch::limitsOf(auth()->user()) !== null ? Branch::defaultFor(auth()->user())?->id : null)
            ->required(fn () => Branch::limitsOf(auth()->user()) !== null)
            ->native(false);
    }

    public static function filter(?string $label = null): Select
    {
        $limited = Branch::limitsOf(auth()->user()) !== null;

        return Select::make('branch_id')
            ->label($label ?? __('Branch'))
            ->options(fn () => self::options())
            ->placeholder($limited ? null : __('All branches'))
            ->selectablePlaceholder(! $limited)
            ->default(fn () => $limited ? Branch::defaultFor(auth()->user())?->id : null)
            ->native(false)
            ->live();
    }

    /** The branch a limited user's report runs for when none is picked; null (all) for everyone else. */
    public static function reportBranch(mixed $picked): ?int
    {
        $picked = $picked === null || $picked === '' ? null : (int) $picked;
        $limits = Branch::limitsOf(auth()->user());
        if ($limits === null) {
            return $picked;
        }

        if ($picked !== null && in_array($picked, $limits, true)) {
            return $picked;
        }

        // A limited user with no branch at all reports on none (-1 matches no branch, and still filters where a
        // query asks ->when($branchId)), never on every branch.
        return Branch::defaultFor(auth()->user())?->id ?? -1;
    }

    /** @return array<int, string> the user's branches, plus the record's own when it is outside them */
    private static function options(mixed $current = null): array
    {
        return Branch::query()
            ->where(fn ($q) => $q->visibleTo(auth()->user())->where('is_active', true))
            ->when($current, fn ($q) => $q->orWhereKey($current))
            ->orderBy('name')->pluck('name', 'id')->all();
    }
}
