<?php

namespace Tests\Feature;

use App\Domain\Access\Screens;
use App\Filament\Support\ErpPage;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PlaceholderPage;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * The honest counter of what is built: every registered screen opens for an
 * administrator and is refused to an operator without rights.
 */
class ScreenRouteTest extends TestCase
{
    /** @return array<string, string> menu key → index url */
    private function screens(): array
    {
        $panel = Filament::getPanel('admin');
        $urls = [];
        foreach ($panel->getResources() as $resource) {
            if (is_subclass_of($resource, ErpResource::class)) {
                $urls[$resource::menuKey()->value] = $resource::getUrl('index');
            }
        }
        foreach ($panel->getPages() as $page) {
            if (is_subclass_of($page, ErpPage::class)) {
                $urls[$page::menuKey()->value] = $page::getUrl();
            }
        }

        return $urls;
    }

    public function test_every_registered_screen_opens_for_an_administrator(): void
    {
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();

        $screens = $this->screens();
        $this->assertNotEmpty($screens);

        foreach ($screens as $key => $url) {
            $this->get($url)->assertOk();
            $this->assertNotNull(Screens::find($key));
        }
    }

    public function test_every_create_page_opens_for_an_administrator(): void
    {
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();

        $opened = 0;
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! is_subclass_of($resource, ErpResource::class) || ! $resource::hasPage('create')) {
                continue;
            }
            $this->get($resource::getUrl('create'))->assertOk();
            $opened++;
        }
        $this->assertGreaterThan(10, $opened);
    }

    public function test_every_registered_screen_is_refused_to_an_operator_without_rights(): void
    {
        $this->seed();
        $this->enableAllModules();
        $this->actingAs(User::factory()->create());

        foreach ($this->screens() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_every_screen_of_the_modules_is_built_except_the_known_placeholders(): void
    {
        $this->seed();
        $this->enableAllModules();
        $built = array_keys($this->screens());
        $placeholders = collect(Filament::getPanel('admin')->getPages())
            ->filter(fn ($page) => is_subclass_of($page, PlaceholderPage::class))
            ->map(fn ($page) => $page::menuKey()->value)
            ->values()
            ->all();

        $registry = app(ModuleRegistry::class);
        foreach (Screens::all() as $key) {
            if (! $key->isReplicated()) {
                continue;
            }
            $this->assertNotNull($registry->ownerOf($key), $key->label().' belongs to no module');
            $this->assertContains($key->value, $built, $key->label().' is not built');
        }

        $this->assertSame([], $placeholders, 'every screen is built');
    }
}
