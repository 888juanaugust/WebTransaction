<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Role;
use App\Domain\Regions\RegionContext;
use App\Models\Region;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides, once per request, which region everything below can see.
 *
 * Runs on both panels, and answers differently for each:
 *
 * - **Staff pinned to a region** — that region, and no way to change it. The
 *   region is on their account, not in their session, so it cannot be talked
 *   into being something else by a crafted URL.
 * - **Staff with no region** — the Owner. They choose, and the choice is kept
 *   in the session. "All regions" is one of the choices and it is read-only:
 *   creating anything in that state is refused by RegionContext, because the
 *   question of which books a new invoice belongs in has no answer.
 * - **A buyer** — the region of the company they belong to. They never choose;
 *   a customer belongs to one region by construction.
 *
 * Anything else — the public site, an artisan command — is left unbound and
 * therefore unfiltered. That is safe because neither renders one person's
 * data to another: the public site shows no prices at all, and a command
 * that creates scoped rows must pin itself first.
 */
class BindRegionContext
{
    /** Where the Owner's chosen region is kept between requests. */
    public const SESSION_KEY = 'wilayah_aktif';

    /** The session value that means "show me every region at once". */
    public const SEMUA = 'semua';

    public function __construct(private readonly RegionContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * By named guard, never by default. Inside a panel request Filament
         * makes that panel's guard the default, so a bare user() on the buyer
         * portal returns the customer — who was then asked staff questions.
         */
        $staf = $request->user('web');

        if ($staf !== null) {
            $this->bindForStaff($request, $staf);

            return $next($request);
        }

        $pembeli = $request->user('customer');

        if ($pembeli !== null) {
            /*
             * Through the company rather than off the buyer directly. A buyer
             * account without a company is already refused at login; reading
             * the region from the company keeps one answer to "which region is
             * this customer in" instead of two that can drift apart.
             */
            $regionId = $pembeli->company?->region_id;

            if ($regionId !== null) {
                $this->context->pinTo((int) $regionId);
            }
        }

        return $next($request);
    }

    private function bindForStaff(Request $request, mixed $staf): void
    {
        /*
         * Marketing is global — the 2026-08 rule. One marketing answers for
         * customers in every region, so their screens read across all books,
         * always, with no switcher. Checked before the pin so a leftover
         * region_id on a marketing account changes nothing. Their writes
         * still land in one region: every mutation they can make goes
         * through a domain class that pins itself to the subject's region.
         */
        if ($staf->role() === Role::Marketing) {
            $this->context->openToAll();

            return;
        }

        // Pinned by their account. Not negotiable, and not in the session.
        if ($staf->region_id !== null) {
            $this->context->pinTo((int) $staf->region_id);

            return;
        }

        /*
         * No region on the account and not the Owner: pinned to the first
         * active region rather than offered the switcher. Null on an ordinary
         * account is "nobody assigned one yet", and the failure mode of
         * treating that as "all of them" is a clerk reading every region's
         * books because of a blank field.
         */
        if (! $staf->isOwner()) {
            $bawaan = Region::where('aktif', true)->orderBy('kode')->first();

            if ($bawaan !== null) {
                $this->context->pinTo($bawaan);
            }

            return;
        }

        $dipilih = $request->session()->get(self::SESSION_KEY);

        if ($dipilih === self::SEMUA) {
            $this->context->openToAll();

            return;
        }

        /*
         * A stored id is checked against the table on every request rather
         * than trusted. A region can be deleted or deactivated while somebody
         * has it selected, and a stale id would otherwise filter every screen
         * to a region that no longer exists — an empty system with no
         * explanation on it.
         */
        $region = is_numeric($dipilih)
            ? Region::where('aktif', true)->find((int) $dipilih)
            : null;

        $region ??= Region::where('aktif', true)->orderBy('kode')->first();

        if ($region !== null) {
            $this->context->pinTo($region);
            $request->session()->put(self::SESSION_KEY, (int) $region->getKey());
        }
    }
}
