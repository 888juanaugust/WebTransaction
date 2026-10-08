<?php

namespace Tests\Feature;

use App\Domain\Access\MenuKey;
use App\Domain\Access\ScreenKind;
use App\Filament\Pages\Workspace;
use App\Filament\Shell\Menu;
use App\Filament\Support\ErpResource;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Tests\TestCase;

/** The workspace shell: the rail and tile menu show what the user may open, and every form tab carries an icon for the side column. */
class ShellMenuTest extends TestCase
{
    public function test_the_workspace_is_home_and_the_dashboard_opens_as_its_first_tab(): void
    {
        $this->seed();
        $this->actingAsAdmin();

        $this->get('/admin')->assertOk()->assertSee('aeWorkspace', false)->assertSee('ae-rail', false);
        $this->get('/admin/dashboard')->assertOk()->assertSee('Sales this month');

        $config = (new Workspace)->shellConfig();
        $this->assertSame('/admin', $config['home']);
        $this->assertSame('/admin/dashboard', $config['dashboard']);
        $this->assertSame(Workspace::MAX_TABS, $config['maxTabs']);
    }

    public function test_an_operator_sees_only_the_tiles_their_rights_allow(): void
    {
        $this->seed();
        $user = User::factory()->create(['is_active' => true]);
        AccessGroup::query()->where('name', 'Sales')->firstOrFail()->users()->attach($user);
        $this->actingAs($user);

        $tiles = collect(Menu::forUser())->flatMap(fn (array $group) => array_column($group['tiles'], 'key'))->all();

        $this->assertContains(MenuKey::SalesOrders->value, $tiles);
        $this->assertContains(MenuKey::Customers->value, $tiles);
        $this->assertNotContains(MenuKey::JournalVouchers->value, $tiles);
        $this->assertNotContains(MenuKey::Preferences->value, $tiles);
        $this->assertNotContains('settings', array_column(Menu::forUser(), 'key'), 'a module without one reachable screen is not on the rail');

        $this->get('/admin')->assertOk()->assertSee('Sales Orders')->assertDontSee('Journal Vouchers');
    }

    public function test_every_tile_has_a_kind_an_icon_and_a_url(): void
    {
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();

        foreach (Menu::forUser() as $group) {
            foreach ($group['tiles'] as $tile) {
                $this->assertNotNull(ScreenKind::tryFrom($tile['kind']), $tile['label']);
                $this->assertNotEmpty($tile['icon'], $tile['label'].' has no icon');
                $this->assertStringStartsWith('/admin/', Menu::path($tile['url']));
            }
        }
        $this->assertCount(count(Menu::screens()), Menu::paths(), 'every screen has its own path');
    }

    public function test_every_form_tab_has_an_icon_for_the_side_column(): void
    {
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();

        $missing = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! is_subclass_of($resource, ErpResource::class)) {
                continue;
            }
            $pages = $resource::getPages();
            $host = isset($pages['create']) ? new ($pages['create']->getPage()) : new ($pages['index']->getPage());
            foreach ($this->tabs($resource::form(Schema::make($host))->getComponents()) as $tab) {
                if ($tab->getIcon() === null) {
                    $missing[] = class_basename($resource).': '.$tab->getLabel();
                }
            }
        }

        $this->assertSame([], $missing, "Form tabs without an icon; add them to App\\Filament\\Support\\SideTabIcons:\n".implode("\n", $missing));
    }

    /**
     * @param  array<int, mixed>  $components
     * @return list<Tab>
     */
    private function tabs(array $components): array
    {
        $tabs = [];
        foreach ($components as $component) {
            if ($component instanceof Tab) {
                $tabs[] = $component;
            }
            $children = rescue(fn () => method_exists($component, 'getDefaultChildComponents') ? $component->getDefaultChildComponents() : [], [], false);
            array_push($tabs, ...$this->tabs($children));
        }

        return $tabs;
    }
}
