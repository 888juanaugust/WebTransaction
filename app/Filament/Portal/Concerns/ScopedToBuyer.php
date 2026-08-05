<?php

declare(strict_types=1);

namespace App\Filament\Portal\Concerns;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Every row a buyer can reach belongs to the buyer's own company.
 *
 * This is the portal's one security-critical rule, and the failure mode is not
 * subtle: a customer reads a competitor's order history, prices and debts. A
 * `where('company_id', …)` written once per table is exactly the thing that
 * gets forgotten on the fourth table, or dropped in a refactor, with nothing
 * failing — so it lives here and applies to the resource's *base* query, which
 * covers the list, the record route, relation managers and global search alike.
 *
 * PortalScopingTest fails the build if a portal resource whose model has a
 * company_id column does not use this trait, so the requirement is derived from
 * the schema rather than from a list somebody has to remember to update.
 *
 * The scope is unconditional. There is no "staff sees everything" branch: the
 * only thing that authenticates on this guard is a buyer, and a branch that can
 * be true is a branch that can be wrong.
 */
trait ScopedToBuyer
{
    use ReadOnlyInPortal;

    /** The column on this resource's model that holds the owning company. */
    protected static string $buyerCompanyColumn = 'company_id';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(static::$buyerCompanyColumn, static::buyerCompanyId());
    }

    /**
     * The signed-in buyer's company.
     *
     * Throws rather than returning null. A null would silently become
     * `where company_id is null` — no rows today, and every row the day someone
     * rewrites it as a nullable filter. No buyer in session is a bug in the
     * middleware, not a query to guess at.
     */
    protected static function buyerCompanyId(): int
    {
        $companyId = static::buyer()->company_id;

        if ($companyId === null) {
            throw new RuntimeException('The signed-in buyer belongs to no company.');
        }

        return $companyId;
    }
}
