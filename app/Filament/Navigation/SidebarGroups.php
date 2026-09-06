<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Support\Icons\Heroicon;

/**
 * The shape of the sidebar, declared once.
 *
 * Eight groups, in this order, each with an icon and collapsed until opened.
 * Every resource and page names one of these constants as its
 * `$navigationGroup`; nothing floats at the top level except the dashboard.
 * A screen that names a string instead of a constant lands in a group of
 * its own, which `SidebarNavigationTest` treats as a failure — a typo must
 * not be able to grow the menu.
 *
 * Measured before this existed: the Owner's sidebar was 57 items and
 * 2,992px tall against a 1,400px viewport — more than two screens of menu,
 * every item open all the time, with the groups that did exist (Laporan,
 * Pembelian, Gudang, Buku besar, Pengaturan) in the order they happened to
 * be registered and thirteen screens belonging to none of them.
 *
 * The order is the order of a day's work: what sells, what it brought in,
 * what was bought, what is on the shelf, what goes out of the door, the
 * books, the reports, and the settings last. Keuangan is new — the money-in
 * screens (faktur, terima pembayaran, giro, uang muka, nota kredit, the two
 * claim queues) used to be filed under Penjualan or nowhere, and finance
 * staff are not sales.
 *
 * **Inventori and Gudang are two groups, because they are two roles.** One
 * group named Gudang used to hold both, and that is a name collision with
 * consequences: `Role::Storage` is *labelled* Gudang, so a section with that
 * heading reads as the packer's — while five of the six screens under it were
 * the catalogue-keeper's, including the catalogue itself. The split is along
 * the line CLAUDE.md already draws. **Inventori** is `Role::Warehouse`'s
 * shelf: the catalogue, both imports, stock transfers, opname, reorder points
 * — what the goods *are* and how many there are. **Gudang** is
 * `Role::Storage`'s, and it holds one screen because a packer has one job:
 * Pengiriman, their own warehouse's queue. A one-item group looks thin and is
 * correct; padding it out would mean handing the packer something that is not
 * theirs.
 *
 * Filament renders a group with an icon as an icon-label-chevron row and
 * remembers each group's collapsed state per browser, which is what the
 * reference design's accordion is. `NavigationItem::childItems()` was the
 * other candidate and was rejected: a parent with no URL of its own renders
 * its children expanded, always, so it cannot be compact.
 */
final class SidebarGroups
{
    public const PENJUALAN = 'Penjualan';

    public const KEUANGAN = 'Keuangan';

    public const PEMBELIAN = 'Pembelian';

    /** `Role::Warehouse` — what the goods are, and how many. */
    public const INVENTORI = 'Inventori';

    /** `Role::Storage` — what goes out of the door. One screen, on purpose. */
    public const GUDANG = 'Gudang';

    public const BUKU_BESAR = 'Buku besar';

    public const LAPORAN = 'Laporan';

    public const PENGATURAN = 'Pengaturan';

    /**
     * The labels, in sidebar order. What the test compares against.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        return [
            self::PENJUALAN,
            self::KEUANGAN,
            self::PEMBELIAN,
            self::INVENTORI,
            self::GUDANG,
            self::BUKU_BESAR,
            self::LAPORAN,
            self::PENGATURAN,
        ];
    }

    /**
     * The groups as the panel registers them.
     *
     * Collapsed by default so a first visit shows eight rows and the
     * dashboard, not fifty-seven. The group holding the current page is held
     * open by the stylesheet regardless of what the browser remembers, so a
     * link somebody follows never lands them on a page whose own menu entry
     * is folded away.
     *
     * @return list<NavigationGroup>
     */
    public static function panel(): array
    {
        return [
            NavigationGroup::make(self::PENJUALAN)
                ->icon(Heroicon::OutlinedShoppingBag)
                ->collapsed(),
            NavigationGroup::make(self::KEUANGAN)
                ->icon(Heroicon::OutlinedBanknotes)
                ->collapsed(),
            NavigationGroup::make(self::PEMBELIAN)
                ->icon(Heroicon::OutlinedShoppingCart)
                ->collapsed(),
            NavigationGroup::make(self::INVENTORI)
                ->icon(Heroicon::OutlinedCube)
                ->collapsed(),
            NavigationGroup::make(self::GUDANG)
                ->icon(Heroicon::OutlinedTruck)
                ->collapsed(),
            NavigationGroup::make(self::BUKU_BESAR)
                ->icon(Heroicon::OutlinedBookOpen)
                ->collapsed(),
            NavigationGroup::make(self::LAPORAN)
                ->icon(Heroicon::OutlinedChartBar)
                ->collapsed(),
            NavigationGroup::make(self::PENGATURAN)
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->collapsed(),
        ];
    }
}
