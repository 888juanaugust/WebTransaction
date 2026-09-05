<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Support\Icons\Heroicon;

/**
 * The shape of the sidebar, declared once.
 *
 * Seven groups, in this order, each with an icon and collapsed until opened.
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
 * what was bought, what is on the shelf, the books, the reports, and the
 * settings last. Keuangan is new — the money-in screens (faktur, terima
 * pembayaran, giro, uang muka, nota kredit, the two claim queues) used to
 * be filed under Penjualan or nowhere, and finance staff are not sales.
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
            self::GUDANG,
            self::BUKU_BESAR,
            self::LAPORAN,
            self::PENGATURAN,
        ];
    }

    /**
     * The groups as the panel registers them.
     *
     * Collapsed by default so a first visit shows seven rows and the
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
            NavigationGroup::make(self::GUDANG)
                ->icon(Heroicon::OutlinedCube)
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
