<?php

namespace Tests\Feature;

use App\Client\ClientServiceProvider;
use App\Modules\ModuleRegistry;
use Tests\Support\FakeModule;
use Tests\TestCase;

/** A client extends the template from config/client.php and app/Client, never by editing the standard modules. */
class ClientOverrideTest extends TestCase
{
    public function test_a_module_listed_in_the_client_config_joins_the_registry(): void
    {
        $this->assertNull(app(ModuleRegistry::class)->find('fake-client-module'));

        config(['client.modules' => [FakeModule::class]]);
        $this->freshRequest();

        $registry = app(ModuleRegistry::class);
        $this->assertSame(FakeModule::class, $registry->find('fake-client-module'));
        $this->assertTrue($registry->isEnabled('fake-client-module'), 'a module without a feature switch is always on');
        $this->assertContains(FakeModule::class, $registry->enabled());
    }

    public function test_the_client_provider_boots_last(): void
    {
        $providers = require base_path('bootstrap/providers.php');

        $this->assertSame(ClientServiceProvider::class, end($providers));
    }
}
