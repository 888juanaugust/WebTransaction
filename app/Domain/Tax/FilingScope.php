<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A tax filing belongs to the NPWP, not to a warehouse.
 *
 * Everywhere else in this system, region scoping is right: one region's books
 * are one region's books, and a total that is not filtered to a single
 * customer stays inside them. **The filing path is the one place where that is
 * wrong by construction**, and it is worth being precise about why rather than
 * leaving six lifted scopes scattered about looking like oversights.
 *
 * The business is a single PT with one NIB and one NPWP — the one in
 * `config/perusahaan.php`. Regions are sets of books and warehouses; they are
 * not legal entities and they do not file separately. There is one SPT Masa
 * PPN per month for the company, and it has to carry every faktur the company
 * issued that month, whichever region's books the sale landed in.
 *
 * Measured before this existed, on one month with three sales in Surabaya's
 * books and two in Jakarta's:
 *
 *     fakturs in the masa    5      PPN Rp 1.650.000
 *     the export contained   3      PPN Rp   660.000
 *     reported as blocked    0
 *     rekap PPN keluaran     Rp   660.000
 *
 * Rp 990.000 of output VAT collected from customers and not reported, with
 * nothing on the screen saying so — the export's own docblock names that as
 * the failure it exists to prevent. Worse than a wrong number that argues with
 * itself: the recap and the export agreed, so the two figures an accountant
 * would cross-check confirmed each other.
 *
 * It was not a matter of remembering to switch region first, either. Finance
 * is the role that files, and `BindRegionContext` pins Finance to the region on
 * their account and returns — there is no switcher for them to forget.
 *
 * So: every read on the way to an SPT goes through here, and `FilingScope::`
 * is the one thing to grep for when asking "what does a filing actually see".
 * Writes are untouched — an export row still stamps the region of whoever made
 * it, which is a true fact about who filed and never a filter on what they
 * filed.
 */
final class FilingScope
{
    /**
     * The whole company's rows, region scope deliberately off.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public static function entityWide(string $model): Builder
    {
        return $model::query()->withoutGlobalScope('region');
    }
}
