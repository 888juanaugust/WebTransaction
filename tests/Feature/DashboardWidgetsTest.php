<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every dashboard widget renders, for every role that can see it.
 *
 * Written after a one-character mistake in a Blade file — a `@php use` block
 * that ended up inside a component slot — turned the whole dashboard into a
 * 500 for the owner. Nothing failed: there was no test that rendered a widget,
 * only tests of the numbers behind them.
 *
 * This enumerates the panel's own registration rather than a hand-written
 * list, so a widget added tomorrow is covered without anybody remembering to
 * add it here.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<class-string<Widget>> */
    private function registeredWidgets(): array
    {
        Filament::setCurrentPanel('admin');

        return array_map(
            fn ($widget) => $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
            Filament::getPanel('admin')->getWidgets(),
        );
    }

    public function test_the_panel_registers_the_widgets_this_test_thinks_it_does(): void
    {
        // If this ever returns nothing, every assertion below passes silently
        // and the guard is worthless.
        $this->assertNotEmpty($this->registeredWidgets());
    }

    public function test_every_widget_renders_for_every_role_that_can_see_it(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::factory()->role($role)->create();

            foreach ($this->registeredWidgets() as $widget) {
                $this->actingAs($user);

                if (! $widget::canView()) {
                    continue;
                }

                Livewire::actingAs($user)
                    ->test($widget)
                    ->assertOk();
            }
        }
    }

    public function test_the_dashboard_itself_opens_for_every_role(): void
    {
        // The widgets can each render and the page still fail — the page is
        // what people actually load.
        foreach (Role::cases() as $role) {
            $this->actingAs(User::factory()->role($role)->create(), 'web')
                ->get('/admin')
                ->assertOk();
        }
    }
}
