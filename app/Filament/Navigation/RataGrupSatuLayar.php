<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationManager;

/**
 * A group holding one visible screen renders as a plain row, not an accordion.
 *
 * Grouping is bookkeeping about *ownership* — which role a screen belongs to,
 * which is what `SidebarGroups` declares and what the tests assert. Painting a
 * heading and a chevron above a single row is a different question, and for a
 * narrow role the answer was clearly no: measured, a packer's menu was four
 * accordions holding one item each — Order, Katalog, Pengiriman, Penjelajah
 * data. Four clicks to reach four screens, and every heading a promise of more
 * underneath it that was not there.
 *
 * The rule is one rule for everybody rather than a special case for the narrow
 * roles: **exactly one visible item, and no child items under it, renders
 * flat.** Visibility is already per-account by the time this runs, so the same
 * group is an accordion for the Owner and a plain row for a packer without
 * either of them being a special case in the code.
 *
 * ## How, and why here
 *
 * A `NavigationGroup` with a blank label renders its items with no heading, no
 * chevron and no `x-collapse` wrapper, and passes `grouped: false` down so each
 * item draws as a top-level row — Filament already has the shape, it just has
 * no rule for choosing it. So flattening is: replace the group with an unnamed
 * one holding the same item.
 *
 * It has to happen *after* `NavigationManager::get()` and not inside it,
 * because that method's final `sortBy` returns `-1` for every blank-label
 * group — unnamed groups are Filament's way of saying "top of the menu, beside
 * the dashboard". Blanking a label before the sort would fling Pengiriman to
 * the top of the sidebar and lose the day's-work order the whole arrangement
 * is built on. Blanking it after leaves the array order alone, and the sidebar
 * renders the array in order.
 *
 * The group's own icon goes with its label, deliberately: Filament throws if a
 * group has an icon and its items do too, and once there is no heading to hang
 * it on, the item's icon is the one that means something.
 */
class RataGrupSatuLayar extends NavigationManager
{
    /**
     * @return array<NavigationGroup>
     */
    public function get(): array
    {
        return array_map(
            fn (NavigationGroup $group): NavigationGroup => $this->layakDiratakan($group)
                ? NavigationGroup::make()->items($group->getItems())
                : $group,
            parent::get(),
        );
    }

    private function layakDiratakan(NavigationGroup $group): bool
    {
        // Already unnamed — the dashboard's group, which has nothing to fold.
        if (blank($group->getLabel())) {
            return false;
        }

        // `getItems()` is declared `array|Arrayable` and is a Collection in
        // practice; normalised rather than assumed either way.
        $items = collect($group->getItems());

        if ($items->count() !== 1) {
            return false;
        }

        /*
         * One item with children is not one row: Filament nests child items
         * under it, so the heading is still standing over more than it says.
         * Left as an accordion.
         */
        return blank($items->first()->getChildItems());
    }
}
