<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every screen must have an opinion about who may open it.
 *
 * Each screen in this panel was gated when it was written, and each was
 * tested on its own. Nothing ever enumerated the set — so when two resources
 * were added without an access decision, Filament's permissive default
 * answered for them and every test still passed.
 *
 * Measured before this file existed, by asking each of the six roles what it
 * could reach: `ProductResource` declared nothing at all, and a Gudang clerk
 * — the one role CLAUDE.md says outright cannot touch the catalogue — could
 * change `qty_per_ctn` from 18 to 1 and then delete the SKU. `OrderResource`
 * answered "yes, anybody" to canViewAny and canDelete for the same reason;
 * no Delete button was rendered, so nothing exploited it.
 *
 * The fix for one resource is a method. The fix for the *category* is this
 * test, which walks whatever the panel actually registers. A resource added
 * next year without a gate fails here rather than appearing in everybody's
 * sidebar.
 */
class PanelAccessDeclaredTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Does this class, or an ancestor we wrote, decide the question itself?
     *
     * Inheriting from a shared base is fine and deliberate — the report pages
     * all defer to `ReportPage::canAccess()`. What is not fine is inheriting
     * from Filament, whose answer is yes.
     */
    private function declaresItself(string $class, string $method): bool
    {
        if (! method_exists($class, $method)) {
            return false;
        }

        $declaring = (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();

        return str_starts_with($declaring, 'App\\');
    }

    /** @return list<string> */
    private function missing(string $class, array $methods): array
    {
        return array_values(array_filter(
            $methods,
            fn (string $m) => ! $this->declaresItself($class, $m),
        ));
    }

    public function test_every_page_decides_who_may_open_it(): void
    {
        $gaps = [];

        foreach (Filament::getPanel('admin')->getPages() as $page) {
            /*
             * The dashboard is the one exception, and it is Filament's own
             * class rather than ours: it is the panel's landing page, so a
             * signed-in staff member reaching it is the point. What it shows
             * is decided by the widgets on it, which are checked below.
             */
            if ($page === Dashboard::class) {
                continue;
            }

            if ($this->missing($page, ['canAccess']) !== []) {
                $gaps[] = $page;
            }
        }

        $this->assertSame([], $gaps, 'Halaman tanpa canAccess sendiri: '.implode(', ', $gaps));
    }

    public function test_every_resource_decides_all_four_verbs(): void
    {
        $gaps = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $missing = $this->missing($resource, ['canViewAny', 'canCreate', 'canEdit', 'canDelete']);

            if ($missing !== []) {
                $gaps[] = class_basename($resource).' → '.implode(', ', $missing);
            }
        }

        /*
         * All four, not just canViewAny. Reading and writing are different
         * questions and a resource that answers one is not answering the
         * other: OrderResource gated create and edit and left view and delete
         * to the default, which is how "nobody may edit an order from here"
         * sat next to "anybody may delete one".
         */
        $this->assertSame([], $gaps, "Resource tanpa keputusan akses sendiri:\n".implode("\n", $gaps));
    }

    public function test_every_widget_decides_who_may_see_it(): void
    {
        $gaps = [];

        foreach (Filament::getPanel('admin')->getWidgets() as $widget) {
            if ($this->missing($widget, ['canView']) !== []) {
                $gaps[] = class_basename($widget);
            }
        }

        // Widgets are the quietest leak of the three: they carry figures onto
        // a page whose own gate said nothing about them.
        $this->assertSame([], $gaps, 'Widget tanpa canView sendiri: '.implode(', ', $gaps));
    }
}
