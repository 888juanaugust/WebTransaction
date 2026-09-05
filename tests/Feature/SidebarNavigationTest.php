<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Navigation\SidebarGroups;
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

    public function test_the_owner_sees_the_seven_groups_in_the_declared_order(): void
    {
        $this->as(Role::Owner);

        $this->assertSame(SidebarGroups::labels(), array_keys($this->sidebarGroups()));
    }

    public function test_only_the_dashboard_floats_outside_a_group(): void
    {
        $this->as(Role::Owner);

        $floating = [];

        foreach (Filament::getPanel('admin')->getNavigation() as $group) {
            if (blank($group->getLabel())) {
                $floating = [...$floating, ...$this->itemLabels($group)];
            }
        }

        // The one screen with no group is the one every group leads back to.
        $this->assertSame(['Dashboard'], $floating);
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

        // The reason a seventh group exists: these were under Penjualan or
        // nowhere, and the people who use them are not sales.
        foreach (['Faktur', 'Terima pembayaran', 'Bilyet giro', 'Uang muka', 'Nota kredit', 'Pelunasan piutang', 'Biaya ekspedisi'] as $label) {
            $this->assertContains($label, $keuangan, "{$label} harus di Keuangan");
        }
    }

    // --- per role ----------------------------------------------------------

    public function test_a_packer_sees_only_the_groups_with_something_in_them(): void
    {
        $this->as(Role::Storage);

        $groups = $this->sidebarGroups();

        /*
         * A group with nothing visible in it is dropped, not shown empty —
         * which is how four of the seven vanish for the narrowest role. The
         * three that remain hold exactly what the access phase left a packer:
         * the order list (every warehouse picks from it), their own shipping
         * queue, and the data explorer with its one dataset. Grouping changes
         * where those sit, not whether they are there.
         */
        $this->assertSame(
            [SidebarGroups::PENJUALAN, SidebarGroups::GUDANG, SidebarGroups::LAPORAN],
            array_keys($groups),
        );
        $this->assertSame(['Order'], $this->itemLabels($groups[SidebarGroups::PENJUALAN]));
        // Katalog joined the packer's Gudang group when reading it was opened
        // to them; Impor barang did not, being the keeper's.
        $this->assertSame(['Pengiriman', 'Katalog'], $this->itemLabels($groups[SidebarGroups::GUDANG]));
        $this->assertSame(['Penjelajah data'], $this->itemLabels($groups[SidebarGroups::LAPORAN]));
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
