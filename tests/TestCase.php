<?php

namespace Tests;

use App\Domain\Shared\Format;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Database\Seeders\Demo\DemoCompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Fixtures;

abstract class TestCase extends BaseTestCase
{
    use Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Format::forgetSymbol();
    }

    /** The demo company on top of the defaults: brands, categories, customers, vendors, items with opening stock. */
    protected function seedDemo(): void
    {
        $this->seed(DemoCompanySeeder::class);
    }

    /** Switch every module on, so a test sees every screen whatever the defaults say. */
    protected function enableAllModules(): void
    {
        app(ModuleRegistry::class)->enableAll();
    }

    /** Drop the per-request singletons, as a new HTTP request would (Filament mounts the sidebar once per request). */
    protected function freshRequest(): void
    {
        app()->forgetScopedInstances();
    }

    /** Log in as an administrator, who passes every access check. */
    protected function actingAsAdmin(): User
    {
        auth()->forgetUser(); // made by the system: an operator signed in before may not make an administrator
        $user = User::factory()->create([
            'access_type' => 'administrator',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        return $user;
    }
}
