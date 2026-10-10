<?php

namespace Tests\Feature;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Filament\Modul;
use App\Filament\Shell\Menu;
use App\Models\User;
use Tests\TestCase;

class PanelBootTest extends TestCase
{
    public function test_the_login_page_carries_the_app_name_until_a_company_is_named(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee(config('app.name'));

        app(Preferensi::class)->set(PreferensiKey::CompanyName, 'Example Co');
        $this->get('/admin/login')->assertOk()->assertSee('Example Co');
    }

    public function test_an_active_administrator_reaches_the_dashboard(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/dashboard')->assertOk()->assertSee('Sales this month');
        $this->get('/admin')->assertOk()->assertSee('aeWorkspace', false)->assertSee('ae-rail', false);
    }

    public function test_an_inactive_account_cannot_open_the_panel(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => false]));

        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_the_rail_holds_the_ten_module_groups_in_order(): void
    {
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();

        $groups = array_column(Menu::forUser(), 'label');

        $this->assertSame(array_map(fn (Modul $m) => $m->getLabel(), Modul::cases()), $groups);
        $this->assertSame(['Company', 'General Ledger', 'Cash & Bank', 'Sales', 'Purchasing', 'Inventory', 'Fixed Assets', 'Tax', 'Reports', 'Settings'], $groups);
    }

    public function test_the_panel_speaks_english(): void
    {
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('Sales Invoices', __('menu.screens.customer__sales-invoice'));
    }
}
