<?php

namespace Tests\Feature;

use App\Client\Access\CentralGroups;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Setup\Installer;
use App\Models\Company\Currency;
use App\Models\Preference;
use App\Models\Sales\Customer;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Database\Seeders\Defaults\AccessGroupSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** erp:install brings an empty database to a company's first login, once. */
class InstallCommandTest extends TestCase
{
    public function test_a_non_interactive_install_sets_the_company_its_modules_and_its_administrator(): void
    {
        $this->artisan('erp:install', [
            '--no-interaction' => true,
            '--company' => 'Example Co',
            '--address' => '1 Example Street',
            '--currency' => 'usd',
            '--fiscal-year-start' => 7,
            '--admin-name' => 'Owner',
            '--admin-email' => 'owner@example.test',
            '--admin-password' => 'secret-pass-12',
            '--disable' => ['payroll', 'fixed-assets'],
            '--enable' => ['sales-extras'],
            '--demo' => true,
        ])->assertSuccessful()->expectsOutputToContain('Example Co is ready');

        $prefs = app(Preferensi::class);
        $this->assertSame('Example Co', $prefs->get(PreferensiKey::CompanyName));
        $this->assertSame('1 Example Street', $prefs->get(PreferensiKey::CompanyAddress));
        $this->assertSame('7', $prefs->get(PreferensiKey::FiscalYearStartMonth));

        $admin = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('Owner', $admin->name);
        $this->assertTrue($admin->isAdministrator());
        $this->assertTrue(Hash::check('secret-pass-12', $admin->password));
        $this->assertTrue($admin->password_change_required, 'a password someone else chose is changed at first sign-in');
        $this->assertSame(1, User::query()->count(), 'no second administrator from the environment');

        $this->assertSame('USD', Currency::query()->where('is_base', true)->value('code'));
        $this->assertEqualsCanonicalizing([...AccessGroupSeeder::GROUPS, CentralGroups::MARKETING, CentralGroups::INVENTORY], AccessGroup::query()->pluck('name')->all());

        $modules = app(ModuleRegistry::class);
        $this->assertFalse($modules->isEnabled('payroll'));
        $this->assertFalse($modules->isEnabled('fixed-assets'));
        $this->assertTrue($modules->isEnabled('sales-extras'));
        $this->assertTrue($modules->isEnabled('tax'), 'untouched modules keep their default');

        $this->assertSame(3, Customer::query()->count(), 'the demo company');
        $this->assertTrue(app(Installer::class)->isInstalled());
        $this->assertNotNull(Preference::query()->whereKey(Installer::INSTALLED_AT)->first());
    }

    public function test_a_second_run_is_refused_without_force_and_force_changes_only_what_is_named(): void
    {
        $this->artisan('erp:install', ['--no-interaction' => true, '--company' => 'Example Co', '--admin-email' => 'owner@example.test', '--admin-password' => 'secret-pass-12', '--disable' => ['payroll'], '--no-demo' => true])->assertSuccessful();
        $this->assertSame(0, Customer::query()->count());

        $this->artisan('erp:install', ['--no-interaction' => true, '--company' => 'Other Co', '--no-demo' => true])
            ->assertFailed()->expectsOutputToContain('already installed');
        $this->assertSame('Example Co', app(Preferensi::class)->get(PreferensiKey::CompanyName));

        $owner = User::query()->where('email', 'owner@example.test')->sole();
        $owner->forceFill(['password' => Hash::make('the-owners-own-one'), 'password_change_required' => false])->save();
        $this->artisan('erp:install', ['--no-interaction' => true, '--force' => true, '--company' => 'Example Co', '--admin-email' => 'owner@example.test', '--admin-password' => 'another-pass-12', '--enable' => ['payroll'], '--no-demo' => true])
            ->assertSuccessful()->expectsOutputToContain('left as it is');
        $this->assertTrue(app(ModuleRegistry::class)->isEnabled('payroll'));
        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('the-owners-own-one', $owner->fresh()->password), 'a forced run never resets a password');
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_an_unknown_module_or_currency_is_refused_before_anything_is_seeded(): void
    {
        $this->artisan('erp:install', ['--no-interaction' => true, '--company' => 'Example Co', '--enable' => ['warp-drive'], '--no-demo' => true])
            ->assertExitCode(2)->expectsOutputToContain('Unknown module "warp-drive"');
        $this->artisan('erp:install', ['--no-interaction' => true, '--company' => 'Example Co', '--currency' => 'XYZ', '--no-demo' => true])
            ->assertExitCode(2)->expectsOutputToContain('Unknown currency');
        $this->assertSame(0, AccessGroup::query()->count());
        $this->assertFalse(app(Installer::class)->isInstalled());
    }

    public function test_depreciation_notes_when_its_module_is_off(): void
    {
        $this->seed();
        app(Preferensi::class)->set(PreferensiKey::FixedAssets, false);

        $this->artisan('erp:depreciate')->assertSuccessful()->expectsOutputToContain('switched off');
    }

    public function test_without_a_password_one_is_made_and_a_short_one_is_refused(): void
    {
        $this->artisan('erp:install', ['--no-interaction' => true, '--company' => 'Example Co', '--admin-password' => 'short', '--no-demo' => true])
            ->assertExitCode(2)->expectsOutputToContain('at least 12 characters');

        $this->artisan('erp:install', ['--no-interaction' => true, '--company' => 'Example Co', '--admin-email' => 'owner@example.test', '--no-demo' => true])
            ->assertSuccessful()->expectsOutputToContain('shown once');
        $owner = User::query()->where('email', 'owner@example.test')->sole();
        $this->assertFalse(Hash::check('password', $owner->password), 'never a known default');
        $this->assertTrue($owner->password_change_required);
    }
}
