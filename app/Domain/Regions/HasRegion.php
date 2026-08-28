<?php

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Models\Region;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * This model's rows belong to one region, and queries never leave it.
 *
 * The filtering is a global scope rather than a `where` on each screen, and
 * that choice is the whole point. Region scoping written per screen covers the
 * screens somebody remembered: the reports would still read every region, the
 * domain services would still post across them, and the leak would show up as a
 * warehouse clerk in Surabaya reading Jakarta's margins — with nothing failing.
 * Applied here it covers Filament resources, relation managers, global search,
 * every report, and every `Invoice::query()` in a service alike.
 *
 * The column is qualified with the table name on purpose. Half the queries in
 * the reports join two scoped tables, and an unqualified `region_id` in a join
 * is ambiguous — Postgres refuses it, which is the good outcome, but only after
 * the query has been written and shipped.
 *
 * Rows are stamped on creation from the same context, so nothing has to
 * remember to pass a region. When the context cannot say — a queue job, a
 * console command, somebody looking across every region — this throws instead
 * of guessing. RegionScopingTest fails the build if a table with a `region_id`
 * column has a model that does not use this trait, so the requirement comes
 * from the schema rather than from a list somebody keeps up to date.
 */
trait HasRegion
{
    public static function bootHasRegion(): void
    {
        static::addGlobalScope('region', function (Builder $query): void {
            /*
             * A buyer is company-scoped, not region-scoped. Since orders
             * split across warehouses (2026-08), one customer's documents
             * legitimately live in several regions' books — and their own
             * portal must show all of them. ScopedToBuyer and the portal's
             * company_id filters are the buyer's isolation; filtering their
             * reads by region would hide their own invoices. Their writes
             * still land in the home region: the context stays pinned, and
             * the creating-stamp below reads the context, not this branch.
             */
            if (auth('customer')->check()) {
                return;
            }

            $regionId = app(RegionContext::class)->regionId();

            if ($regionId === null) {
                return;
            }

            $query->where($query->getModel()->getTable().'.region_id', $regionId);
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('region_id') === null) {
                $model->setAttribute('region_id', app(RegionContext::class)->requireRegionId());
            }
        });
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Deliberately step outside the current region.
     *
     * Rare and always suspicious, which is why it is spelled out at the call
     * site rather than being the default. The legitimate uses are a job walking
     * every region in turn and a consolidated report the owner asked for.
     */
    public function scopeAcrossRegions(Builder $query): Builder
    {
        return $query->withoutGlobalScope('region');
    }

    public function scopeInRegion(Builder $query, Region|int $region): Builder
    {
        return $query
            ->withoutGlobalScope('region')
            ->where(
                $query->getModel()->getTable().'.region_id',
                $region instanceof Region ? $region->getKey() : $region,
            );
    }
}
