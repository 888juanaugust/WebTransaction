<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\BindRegionContext;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Owner changes which region they are looking at.
 *
 * A plain POST and a session write — deliberately not Livewire. The choice has
 * to survive navigation, outlive any one component, and be re-validated on
 * every request by the middleware anyway, so the session is the only state
 * worth having and a form is the simplest thing that writes it.
 *
 * Owner only, enforced here as well as in the UI. For everyone else the region
 * is pinned on their account, and a crafted POST changing it would be exactly
 * the escalation the pinning exists to prevent.
 */
class GantiWilayahController
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isOwner() ?? false, 403);

        $pilihan = (string) $request->input('wilayah', '');

        if ($pilihan === BindRegionContext::SEMUA) {
            $request->session()->put(BindRegionContext::SESSION_KEY, BindRegionContext::SEMUA);

            return back();
        }

        $region = Region::query()
            ->where('aktif', true)
            ->find((int) $pilihan);

        abort_if($region === null, 422, 'Cabang tidak dikenal.');

        $request->session()->put(BindRegionContext::SESSION_KEY, (int) $region->getKey());

        return back();
    }
}
