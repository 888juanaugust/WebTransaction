<?php

namespace Tests\Feature;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Filament\Pages\Reports\DepreciationSchedule;
use App\Filament\Pages\Reports\ReportRegistry;
use App\Filament\Resources\Company\Departments\DepartmentResource;
use App\Filament\Resources\FixedAssets\FixedAssets\FixedAssetResource;
use App\Filament\Resources\GeneralLedger\PayrollEntries\PayrollEntryResource;
use App\Filament\Resources\Sales\CheckIns\CheckInResource;
use App\Filament\Resources\Settings\AccessGroups\AccessGroupResource;
use App\Models\Settings\AccessGroup;
use App\Modules\ModuleRegistry;
use Tests\TestCase;

/** A module switched off in Preferences disappears: its screens refuse, its sidebar group goes, its reports and rights hide; on again, everything is back. */
class ModuleToggleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAsAdmin();
    }

    public function test_switching_fixed_assets_off_hides_its_screens_reports_and_rights(): void
    {
        $this->get(FixedAssetResource::getUrl('index'))->assertOk();
        $this->get('/admin')->assertOk()->assertSee('Fixed Assets');
        $this->assertTrue(ReportRegistry::all()->contains(DepreciationSchedule::class));
        $this->assertTrue(app(ModuleRegistry::class)->isEnabled('fixed-assets'));

        app(Preferensi::class)->set(PreferensiKey::FixedAssets, false);
        $this->freshRequest();
        $this->get(FixedAssetResource::getUrl('index'))->assertForbidden();
        $this->get(FixedAssetResource::getUrl('create'))->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('Fixed Assets');
        $this->assertFalse(ReportRegistry::all()->contains(DepreciationSchedule::class));
        $this->get('/admin/report/depreciation-schedule')->assertForbidden();
        $group = AccessGroup::query()->where('name', 'Finance')->firstOrFail();
        $this->get(AccessGroupResource::getUrl('edit', ['record' => $group]))->assertOk()->assertDontSee('Asset Categories');

        app(Preferensi::class)->set(PreferensiKey::FixedAssets, true);
        $this->freshRequest();
        $this->get(FixedAssetResource::getUrl('index'))->assertOk();
        $this->get('/admin')->assertSee('Fixed Assets');
        $this->get(AccessGroupResource::getUrl('edit', ['record' => $group]))->assertSee('Asset Categories');
    }

    public function test_optional_modules_are_off_until_switched_on(): void
    {
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('payroll'));
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('sales-extras'));
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('departments'));
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('projects'));
        $this->get(DepartmentResource::getUrl('index'))->assertForbidden();
        $this->get(PayrollEntryResource::getUrl('index'))->assertForbidden();
        $this->get(CheckInResource::getUrl('index'))->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('Payroll Entries')->assertDontSee('Check-ins');

        app(Preferensi::class)->setMany([PreferensiKey::Payroll->value => true, PreferensiKey::SalesExtras->value => true, PreferensiKey::Department->value => true]);
        $this->freshRequest();
        $this->get(DepartmentResource::getUrl('index'))->assertOk();
        $this->get(PayrollEntryResource::getUrl('index'))->assertOk();
        $this->get(CheckInResource::getUrl('index'))->assertOk();
        $this->get('/admin')->assertSee('Payroll Entries')->assertSee('Check-ins');
    }

    public function test_every_core_module_is_always_on_and_every_screen_has_exactly_one_owner(): void
    {
        $registry = app(ModuleRegistry::class);
        foreach (['settings', 'company', 'general-ledger', 'cash-bank', 'sales', 'purchasing', 'inventory', 'reports'] as $key) {
            $this->assertTrue($registry->isEnabled($key), $key);
        }
        $this->assertFalse($registry->isEnabled('nothing-like-this'));

        $seen = [];
        foreach ($registry->all() as $module) {
            foreach ($module::menuKeys() as $menuKey) {
                $this->assertArrayNotHasKey($menuKey->value, $seen, $menuKey->label().' is owned twice');
                $seen[$menuKey->value] = $module;
            }
        }
        $this->assertCount(count(array_unique(array_values($registry->morphMap()))), $registry->morphMap(), 'every morph alias names its own model');
    }
}
