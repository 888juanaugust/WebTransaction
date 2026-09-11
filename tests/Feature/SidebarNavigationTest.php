<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Navigation\SidebarGroups;
use App\Filament\Pages\ImporBarang;
use App\Filament\Pages\Pengiriman;
use App\Filament\Resources\Products\ProductResource;
use App\Models\CustomerUser;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use DOMDocument;
use DOMXPath;
use Filament\Enums\UserMenuPosition;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar has a shape, and this pins it.
 *
 * Measured before the shape existed: the Owner's menu was 57 items and
 * 2,992px tall against a 1,400px viewport, thirteen screens belonged to no
 * group, and the groups that did exist appeared in the order they happened
 * to be registered. Nothing was wrong in the sense a test could catch — every
 * link worked — and the menu was still more than twice the screen.
 *
 * Two kinds of drift are guarded here. A screen added without a group floats
 * to the top and the menu grows by one, forever; a `$navigationGroup` typed as
 * a string instead of a `SidebarGroups` constant creates a group of its own.
 * Both are one line, both look fine in review, and both fail below.
 */
class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role->value, 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    /**
     * The navigation as the signed-in account sees it, labelled groups only.
     *
     * @return array<string, NavigationGroup> label => group
     */
    private function sidebarGroups(): array
    {
        $out = [];

        foreach (Filament::getPanel('admin')->getNavigation() as $group) {
            if (filled($group->getLabel())) {
                $out[$group->getLabel()] = $group;
            }
        }

        return $out;
    }

    /**
     * The menu as it actually renders, in order, flattened rows included.
     *
     * A group holding one screen comes back with a null label — that is the
     * shape Filament draws as a plain row, and `sidebarGroups()` above cannot
     * see it, so the two helpers answer two different questions: what is
     * grouped, and what a person looking at the sidebar sees.
     *
     * @return list<array{label: ?string, items: list<string>}>
     */
    private function sidebarRows(): array
    {
        return array_map(
            fn (NavigationGroup $group): array => [
                'label' => filled($group->getLabel()) ? $group->getLabel() : null,
                'items' => $this->itemLabels($group),
            ],
            array_values(Filament::getPanel('admin')->getNavigation()),
        );
    }

    /** @return list<?string> */
    private function rowLabels(): array
    {
        return array_map(fn (array $row): ?string => $row['label'], $this->sidebarRows());
    }

    /** @return list<string> */
    private function itemLabels(NavigationGroup $group): array
    {
        return array_values(array_map(
            fn (NavigationItem $item): string => $item->getLabel(),
            collect($group->getItems())->all(),
        ));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    // --- the shape ---------------------------------------------------------

    public function test_the_owner_sees_the_declared_groups_in_the_declared_order(): void
    {
        $this->as(Role::Owner);

        /*
         * Every declared group still appears, in the declared order — except
         * Gudang, which holds one screen for this role and so renders as a
         * plain row. The order is what matters and it is untouched: the flat
         * row sits exactly where the heading would have been.
         */
        $this->assertSame(
            [
                null,               // Dashboard
                SidebarGroups::PENJUALAN,
                SidebarGroups::KEUANGAN,
                SidebarGroups::PEMBELIAN,
                SidebarGroups::INVENTORI,
                null,               // Gudang → Pengiriman, one screen
                SidebarGroups::BUKU_BESAR,
                SidebarGroups::LAPORAN,
                SidebarGroups::PENGATURAN,
            ],
            $this->rowLabels(),
        );
    }

    public function test_a_group_with_one_screen_renders_as_a_plain_row(): void
    {
        $this->as(Role::Owner);

        $rows = $this->sidebarRows();

        // Position five, between Inventori and Buku besar, where the Gudang
        // heading would be. That it keeps its place is the whole reason the
        // flattening happens after Filament's sort rather than inside it —
        // an unlabelled group built before the sort is flung to the top.
        $this->assertSame(['label' => null, 'items' => ['Pengiriman']], $rows[5]);

        // Its neighbours are untouched: more than one screen, still a heading.
        $this->assertSame(SidebarGroups::INVENTORI, $rows[4]['label']);
        $this->assertSame(SidebarGroups::BUKU_BESAR, $rows[6]['label']);
    }

    public function test_a_group_with_two_screens_keeps_its_heading(): void
    {
        $this->as(Role::Owner);

        // The rule is about one, not about few. Every group the Owner sees
        // with more than one screen in it still paints a heading.
        foreach ($this->sidebarRows() as $row) {
            if (count($row['items']) > 1) {
                $this->assertNotNull(
                    $row['label'],
                    'grup dengan '.count($row['items']).' layar kehilangan judulnya: '.implode(', ', $row['items']),
                );
            }
        }

        $this->assertNotEmpty($this->sidebarGroups(), 'ada judul yang tersisa');
    }

    public function test_the_dashboard_still_stands_on_its_own(): void
    {
        $this->as(Role::Owner);

        // It was never in a group, and flattening must not have swept it in
        // with the rest: it is the row every group leads back to.
        $this->assertSame(['label' => null, 'items' => ['Dashboard']], $this->sidebarRows()[0]);
    }

    public function test_every_registered_screen_names_a_constant_not_a_string(): void
    {
        $panel = Filament::getPanel('admin');
        $known = SidebarGroups::labels();
        $strays = [];

        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            if ($class === Dashboard::class) {
                continue;
            }

            if (! in_array($class::getNavigationGroup(), $known, true)) {
                $strays[] = class_basename($class).' → '.var_export($class::getNavigationGroup(), true);
            }
        }

        $this->assertSame([], $strays, "Layar di luar SidebarGroups:\n".implode("\n", $strays));
    }

    public function test_every_group_is_an_accordion_with_an_icon_and_starts_folded(): void
    {
        $this->as(Role::Owner);

        foreach ($this->sidebarGroups() as $label => $group) {
            $this->assertTrue($group->isCollapsible(), "{$label} harus bisa dilipat");
            $this->assertTrue($group->isCollapsed(), "{$label} harus terlipat saat pertama dibuka");
            $this->assertNotNull($group->getIcon(), "{$label} harus punya ikon");
            $this->assertNotEmpty($group->getItems(), "{$label} kosong tapi masih tampil");
        }
    }

    public function test_the_money_in_screens_are_finance_not_sales(): void
    {
        $this->as(Role::Owner);

        $keuangan = $this->itemLabels($this->sidebarGroups()[SidebarGroups::KEUANGAN]);

        // The reason Keuangan exists as its own group: these were under
        // Penjualan or nowhere, and the people who use them are not sales.
        foreach (['Faktur', 'Terima pembayaran', 'Bilyet giro', 'Uang muka', 'Nota kredit', 'Pelunasan piutang', 'Biaya ekspedisi'] as $label) {
            $this->assertContains($label, $keuangan, "{$label} harus di Keuangan");
        }
    }

    public function test_the_two_groupings_people_look_for_have_a_row_of_their_own(): void
    {
        /*
         * "Laporan penjualan per sales" and "barang paling laku" — asked for
         * as reports in the owner's revision list, and both had existed for
         * months behind the Penjualan report's grouping dropdown. One page,
         * three rows: the plain report, then the two groupings, each a deep
         * link that opens the sheet already grouped.
         */
        $this->as(Role::Owner);

        $laporan = $this->sidebarGroups()[SidebarGroups::LAPORAN];
        $labels = $this->itemLabels($laporan);

        $penjualan = array_search('Penjualan', $labels, true);
        $this->assertNotFalse($penjualan);

        $this->assertSame(
            ['Penjualan', 'Omset per sales', 'Barang paling laku', 'KPI'],
            array_slice($labels, $penjualan, 4),
            'the plain report, its two shortcuts, then KPI — in that order',
        );

        $urls = [];

        foreach (collect($laporan->getItems()) as $item) {
            $urls[$item->getLabel()] = $item->getUrl();
        }

        // The rows are the same page, told which grouping to open on.
        $this->assertStringEndsWith('/admin/laporan/penjualan', $urls['Penjualan']);
        $this->assertStringEndsWith('/admin/laporan/penjualan?dimensi=sales', $urls['Omset per sales']);
        $this->assertStringEndsWith('/admin/laporan/penjualan?dimensi=barang', $urls['Barang paling laku']);
    }

    // --- per role ----------------------------------------------------------

    public function test_a_packer_sees_only_the_groups_with_something_in_them(): void
    {
        $this->as(Role::Storage);

        /*
         * A group with nothing visible in it is dropped, not shown empty —
         * which is how four of the eight vanish for the narrowest role. Each
         * of the four that survive holds exactly one screen for this account,
         * so every one of them flattens and the packer's menu is a flat list.
         *
         * This is the measured complaint the flattening rule exists for: four
         * accordions, one item under each, four clicks to reach four screens,
         * and every heading promising more underneath than was there.
         *
         * The screens themselves are what the access phase left a packer: the
         * order list (every warehouse picks from it), the catalogue they may
         * read but not write, their own shipping queue, and the data explorer
         * with its one dataset. Impor barang is absent — this role could not
         * open it wherever it sat.
         */
        $this->assertSame(
            [
                ['label' => null, 'items' => ['Dashboard']],
                ['label' => null, 'items' => ['Order']],
                ['label' => null, 'items' => ['Katalog']],
                ['label' => null, 'items' => ['Pengiriman']],
                ['label' => null, 'items' => ['Penjelajah data']],
            ],
            $this->sidebarRows(),
        );

        // Not one heading, and so not one chevron, for this account.
        $this->assertSame([], $this->sidebarGroups());
    }

    public function test_the_packers_screens_are_still_grouped_underneath(): void
    {
        /*
         * Flattening is presentation, not filing. The screens keep the group
         * they declare — which is what governs the sidebar's order and what
         * the ownership rules are written against — even for the account that
         * never sees a heading. Asserted off the classes rather than the
         * rendered menu, because these two must be allowed to disagree.
         */
        $this->assertSame(SidebarGroups::GUDANG, Pengiriman::getNavigationGroup());
        $this->assertSame(SidebarGroups::INVENTORI, ProductResource::getNavigationGroup());
        $this->assertSame(SidebarGroups::PENJUALAN, ImporBarang::getNavigationGroup());
    }

    public function test_gudang_is_the_packers_group_and_holds_only_their_one_job(): void
    {
        /*
         * The name collision this split exists to end. `Role::Storage` is
         * *labelled* Gudang, so a sidebar section with that heading reads as
         * the packer's — and it used to hold six screens, five of them the
         * catalogue-keeper's, the catalogue itself among them. Nothing was
         * broken; the menu simply asserted that the role which may not write
         * the catalogue owned the section the catalogue lived in.
         *
         * Gudang now holds Pengiriman and nothing else, which is the whole of
         * what CLAUDE.md gives that role: "its warehouse's shipping queue —
         * pick list, surat jalan, ship, complete". A one-item group looks thin
         * and is correct. Anything else appearing here is a screen filed under
         * the wrong role.
         */
        $this->as(Role::Owner);

        // Read off the classes, not the menu: Gudang holds one screen and so
        // renders without a heading, which is a drawing decision and must not
        // be able to hide a screen being filed under the wrong role.
        $inGudang = [];

        foreach ([...Filament::getPanel('admin')->getResources(), ...Filament::getPanel('admin')->getPages()] as $class) {
            if ($class::getNavigationGroup() === SidebarGroups::GUDANG) {
                $inGudang[] = class_basename($class);
            }
        }

        $this->assertSame(['Pengiriman'], $inGudang);

        // And the five that moved are all present under the keeper's heading.
        $inventori = $this->itemLabels($this->sidebarGroups()[SidebarGroups::INVENTORI]);

        foreach (['Katalog', 'Transfer gudang', 'Stok opname', 'Titik pesan ulang', 'Impor harga & barang'] as $label) {
            $this->assertContains($label, $inventori, "{$label} harus di Inventori");
        }
    }

    public function test_the_two_bulk_importers_stand_together_under_penjualan(): void
    {
        /*
         * Filed wrong once and worth pinning. The group is labelled **Gudang**
         * and `Role::Storage` is labelled **Gudang** — so anything in that
         * section reads as the packer's, and the packer is precisely the role
         * that may not write the catalogue. Inventori's work living under a
         * heading named after Storage is how the two roles get confused, which
         * is the confusion the whole access phase was about.
         *
         * The importers are also a pair: one register of who you sell to, one
         * of what you sell, same upload, same preview, same three counts.
         * Adjacent is how a person learns the second from the first.
         */
        $this->as(Role::Owner);

        $penjualan = $this->itemLabels($this->sidebarGroups()[SidebarGroups::PENJUALAN]);

        $this->assertContains('Impor barang', $penjualan);
        $this->assertContains('Impor pelanggan', $penjualan);

        $this->assertSame(
            1,
            array_search('Impor pelanggan', $penjualan, true) - array_search('Impor barang', $penjualan, true),
            'the two importers must be adjacent, item then customer',
        );

        // And not left behind in the packer's section. Off the class, because
        // Gudang holds one screen and so draws no heading to look inside.
        $this->assertSame(SidebarGroups::PENJUALAN, ImporBarang::getNavigationGroup());
    }

    public function test_grouping_did_not_widen_what_a_role_can_see(): void
    {
        // Sales: no books, no settings, no purchasing — same as before the
        // grouping, just folded.
        $this->as(Role::Sales);
        $labels = array_keys($this->sidebarGroups());

        foreach ([SidebarGroups::BUKU_BESAR, SidebarGroups::PENGATURAN, SidebarGroups::PEMBELIAN] as $closed) {
            $this->assertNotContains($closed, $labels, "sales tidak boleh melihat {$closed}");
        }

        $this->assertContains(SidebarGroups::PENJUALAN, $labels);
    }

    // --- the account card and the logo ------------------------------------

    public function test_the_account_lives_in_the_sidebar_footer_not_the_topbar(): void
    {
        $this->as(Role::Owner);

        $this->assertSame(UserMenuPosition::Sidebar, Filament::getPanel('admin')->getUserMenuPosition());

        $x = $this->xpath($this->get('/admin')->getContent());

        // Whole-token match: a substring `contains()` also catches the
        // trigger's own `fi-user-menu-trigger-text` span and counts two.
        $trigger = "*[contains(concat(' ', normalize-space(@class), ' '), ' fi-user-menu-trigger ')]";

        $this->assertSame(1, $x->query("//*[contains(@class,'fi-sidebar-footer')]//{$trigger}")->length, 'kartu akun di kaki sidebar');
        $this->assertSame(0, $x->query("//*[contains(@class,'fi-topbar')]//{$trigger}")->length, 'tidak ada menu akun di topbar');
    }

    public function test_the_caption_under_the_name_says_who_this_is(): void
    {
        $this->as(Role::Owner);

        $x = $this->xpath($this->get('/admin')->getContent());
        $caption = $x->query("//*[contains(@class,'wt-akun-keterangan')]");

        $this->assertSame(1, $caption->length);
        $this->assertSame(Role::Owner->label(), trim($caption->item(0)->textContent));
    }

    public function test_a_packer_sees_their_warehouse_under_their_name(): void
    {
        $warehouse = Warehouse::factory()->create(['nama' => 'Gudang Timur']);
        $packer = User::factory()->create([
            'role' => Role::Storage->value,
            'is_active' => true,
            'warehouse_id' => $warehouse->id,
            'region_id' => $warehouse->region_id,
        ]);
        $this->actingAs($packer);

        $x = $this->xpath($this->get('/admin')->getContent());
        $caption = trim($x->query("//*[contains(@class,'wt-akun-keterangan')]")->item(0)->textContent);

        // Two packers' screens are identical but for this line.
        $this->assertStringContainsString(Role::Storage->label(), $caption);
        $this->assertStringContainsString('Gudang Timur', $caption);
    }

    public function test_a_buyer_sees_their_company_under_their_name(): void
    {
        $buyer = CustomerUser::factory()->create();

        $x = $this->xpath($this->actingAs($buyer, 'customer')->get('/portal')->getContent());
        $caption = $x->query("//*[contains(@class,'wt-akun-keterangan')]");

        $this->assertSame(1, $caption->length);
        $this->assertSame($buyer->company->nama, trim($caption->item(0)->textContent));
    }

    public function test_the_brand_is_the_mark_alone(): void
    {
        $this->as(Role::Owner);

        // The role used to be rendered beside the logo by a view; it is now
        // a caption in the footer, and the logo is a URL or nothing.
        $this->assertFileDoesNotExist(resource_path('views/filament/brand.blade.php'));

        $x = $this->xpath($this->get('/admin')->getContent());

        foreach ($x->query("//*[contains(@class,'fi-logo')]") as $logo) {
            $this->assertStringNotContainsString(Role::Owner->label(), $logo->textContent);
        }
    }

    public function test_the_region_switcher_stayed_in_the_topbar(): void
    {
        Region::query()->create(['kode' => 'JKT', 'nama' => 'Jakarta', 'aktif' => true]);

        $owner = $this->as(Role::Owner);
        $owner->forceFill(['region_id' => null])->save();

        $x = $this->xpath($this->get('/admin')->getContent());

        /*
         * The switcher used to hang off USER_MENU_BEFORE, which renders inside
         * the user menu wherever the menu is. Moving the menu into the sidebar
         * would have taken the switcher with it, silently.
         */
        $this->assertSame(1, $x->query("//*[contains(@class,'fi-topbar')]//select[@id='wilayah-aktif']")->length, 'pemilih wilayah di topbar');
        $this->assertSame(0, $x->query("//*[contains(@class,'fi-sidebar')]//select[@id='wilayah-aktif']")->length, 'pemilih wilayah tidak ikut ke sidebar');
    }

    public function test_the_avatar_is_drawn_here_and_fetched_from_nowhere(): void
    {
        $owner = $this->as(Role::Owner);
        $owner->forceFill(['name' => 'Budi Santoso'])->save();

        $x = $this->xpath($this->get('/admin')->getContent());
        $src = $x->query("//*[contains(@class,'fi-sidebar-footer')]//img[contains(@class,'fi-avatar') or contains(@class,'fi-user-avatar')]/@src");

        $this->assertGreaterThan(0, $src->length, 'avatar di kartu akun');

        $value = $src->item(0)->nodeValue;

        // A data URI: an SVG the server drew, not a URL a browser fetches.
        $this->assertStringStartsWith('data:image/svg+xml', $value);
        $this->assertStringContainsString(rawurlencode('>BS<'), $value, 'inisial dari nama');
        $this->assertStringNotContainsString('ui-avatars.com', $value);
    }

    public function test_the_section_heading_is_real_text(): void
    {
        $this->as(Role::Owner);

        $x = $this->xpath($this->get('/admin')->getContent());
        $heading = $x->query("//*[contains(@class,'fi-sidebar-nav')]//*[contains(@class,'wt-sidebar-bagian')]");

        $this->assertSame(1, $heading->length);
        $this->assertSame('Utama', trim($heading->item(0)->textContent));
    }
}
