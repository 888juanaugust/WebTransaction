<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Models\CustomerUser;
use App\Models\User;
use App\Support\BrandColors;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The FixFlow design system, pinned where it would otherwise drift.
 *
 * A restyle is mostly a matter of taste and screenshots, and two parts of it
 * are not. Those are what this file is for.
 *
 * The first is a **promise**: `resources/views/publik/kebijakan-privasi`
 * tells the public this site loads "no CDN webfonts". Filament's default
 * provider for a named font fetches it from Google, so `->font('Cairo')`
 * without a provider would have quietly broken a published privacy claim —
 * no error, no visible difference, just every staff and buyer page reaching
 * out to fonts.gstatic.com. The font is committed to public/fonts instead,
 * and the tests below assert the rendered HTML never names an external host.
 *
 * The second is **arithmetic**: Filament paints solid buttons and active
 * states with shade 600 of a ramp, so #4F46E5 has to sit at 600 rather than
 * be named somewhere. `Color::hex()` does not do that — it keeps the hue and
 * applies a generic curve — which is why the ramp is declared by hand and
 * why the value is asserted here.
 *
 * The tokens themselves are checked against the design document's own
 * numbers, so that a later tweak by feel has to be a deliberate edit to a
 * test rather than a drift nobody notices.
 */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'resources/css/filament/admin/theme.css';

    /** Hosts a webfont would come from if somebody reached for the default. */
    private const CDN_HOSTS = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'fonts.bunny.net',
        'cdn.jsdelivr.net',
        'unpkg.com',
    ];

    private function theme(): string
    {
        return file_get_contents(base_path(self::THEME));
    }

    /**
     * The stylesheet with its prose removed.
     *
     * These files explain themselves at length, and several of the
     * explanations quote the very strings the assertions below look for — the
     * note about `.fi-topbar > nav` matching nothing names it in order to say
     * so. Asserting against the raw text makes a comment able to fail a test,
     * which teaches people to stop writing comments.
     */
    private function rules(string $css): string
    {
        return preg_replace('#/\\*.*?\\*/#s', '', $css);
    }

    // --- the promise -------------------------------------------------------

    public function test_both_panels_serve_cairo_from_this_application(): void
    {
        foreach (['admin', 'portal'] as $id) {
            $panel = Filament::getPanel($id);

            $this->assertSame('Cairo', $panel->getFontFamily(), "{$id} font family");
            $this->assertSame(LocalFontProvider::class, $panel->getFontProvider(), "{$id} font provider");
            $this->assertStringContainsString('/fonts/cairo.css', $panel->getFontHtml()->toHtml(), "{$id} font url");
        }
    }

    public function test_the_font_is_actually_committed_next_to_its_licence(): void
    {
        // A stylesheet pointing at files nobody committed fails in exactly the
        // way a self-hosted font is meant to avoid: silently, as a fallback.
        foreach ([
            'public/fonts/cairo.css',
            'public/fonts/Cairo-Variable-latin.woff2',
            'public/fonts/Cairo-Variable-latin-ext.woff2',
            'public/fonts/Cairo-OFL.txt',
        ] as $path) {
            $this->assertFileExists(base_path($path));
        }

        $css = $this->rules(file_get_contents(base_path('public/fonts/cairo.css')));

        // Two subsets, each fenced by unicode-range, so the browser fetches
        // the second only when a character in it is drawn.
        $this->assertSame(2, substr_count($css, '@font-face'));
        $this->assertSame(2, substr_count($css, 'unicode-range'));
    }

    public function test_no_panel_page_reaches_a_font_cdn(): void
    {
        $pages = [
            '/admin/login' => null,
            '/portal/login' => null,
            '/admin' => User::factory()->create(['role' => Role::Owner->value, 'is_active' => true]),
        ];

        foreach ($pages as $url => $actor) {
            if ($actor !== null) {
                $this->actingAs($actor);
            }

            $html = $this->get($url)->getContent();

            foreach (self::CDN_HOSTS as $host) {
                $this->assertStringNotContainsString(
                    $host,
                    $html,
                    "{$url} memuat aset dari {$host} — kebijakan privasi menjanjikan tidak ada webfont CDN.",
                );
            }

            $this->assertStringContainsString('/fonts/cairo.css', $html, "{$url} tidak memuat Cairo lokal");
        }
    }

    public function test_the_buyer_portal_is_on_the_same_system_as_the_panel(): void
    {
        $buyer = CustomerUser::factory()->create();

        $html = $this->actingAs($buyer, 'customer')->get('/portal')->getContent();

        // One design system across both surfaces, not two that drifted apart.
        $this->assertStringContainsString('/fonts/cairo.css', $html);

        foreach (self::CDN_HOSTS as $host) {
            $this->assertStringNotContainsString($host, $html);
        }
    }

    // --- the arithmetic ----------------------------------------------------

    public function test_the_primary_ramp_puts_indigo_where_filament_paints_buttons(): void
    {
        $palette = BrandColors::panel();

        $this->assertSame(BrandColors::Indigo, $palette['primary']);

        /*
         * #4F46E5 in oklch. Shade 600 is the one Filament actually uses for a
         * solid button, an active nav item and a focus ring, so this is the
         * single value the whole design system hangs on.
         */
        $this->assertSame('oklch(0.511 0.230 276.966)', BrandColors::Indigo[600]);

        // Eleven steps, ascending keys — an incomplete ramp leaves Filament
        // interpolating hover and disabled states from nothing.
        $this->assertSame(
            [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950],
            array_keys(BrandColors::Indigo),
        );
    }

    public function test_red_and_green_survive_the_redesign(): void
    {
        $palette = BrandColors::panel();

        /*
         * The two colours that carry meaning rather than brand. Red means
         * something is wrong — an overdue faktur, short stock, a destructive
         * button — and green is what finance scans a list for ("Lunas",
         * "Selesai"). A redesign that painted either of them indigo would
         * look tidier and read worse.
         */
        $this->assertNotSame($palette['primary'], $palette['danger']);
        $this->assertNotSame($palette['primary'], $palette['success']);
        $this->assertSame(BrandColors::Red, $palette['danger']);
    }

    public function test_the_logo_blue_is_kept_for_the_shopfront_and_the_documents(): void
    {
        /*
         * #073185 is the logo, the public site and every printed faktur. The
         * panels have moved to indigo and those have not — see
         * BrandColors::panel(). Keeping the ramp is what makes that decision
         * reversible instead of archaeological.
         */
        $this->assertSame('oklch(0.348 0.148 262.160)', BrandColors::Blue[600]);
        $this->assertStringContainsString('#073185', file_get_contents(base_path('app/Support/BrandColors.php')));
    }

    // --- the tokens --------------------------------------------------------

    public function test_the_theme_carries_the_design_documents_own_numbers(): void
    {
        $theme = $this->theme();

        foreach ([
            '#4f46e5' => 'primary indigo',
            '#f8fafc' => 'page ground',
            '#ffffff' => 'surface',
            '#111827' => 'ink',
        ] as $hex => $what) {
            $this->assertStringContainsString($hex, $theme, "token hilang: {$what}");
        }

        // rounded-3xl on containers, rounded-xl on controls, and the topbar
        // height the layout section specifies.
        $this->assertStringContainsString('--wt-radius-card: 24px', $theme);
        $this->assertStringContainsString('--wt-radius-control: 12px', $theme);
        $this->assertStringContainsString('--wt-topbar-h: 56px', $theme);
    }

    public function test_dark_mode_keys_off_the_class_filament_actually_toggles(): void
    {
        $theme = $this->rules($this->theme());

        /*
         * Filament puts `.dark` on <html> and does not follow the OS once a
         * user has chosen. A previous version of this file used a media query
         * and looked correct only while the two agreed: a user on a light
         * laptop switching the panel to dark got white cards on a near-black
         * page. The guard is that no colour rule may hang off the media query.
         */
        $this->assertStringNotContainsString('prefers-color-scheme', $theme);
        $this->assertStringContainsString('html.dark', $theme);
        $this->assertStringContainsString(':where(html:not(.dark))', $theme);
    }

    public function test_both_panels_collapse_their_sidebar(): void
    {
        foreach (['admin', 'portal'] as $id) {
            $this->assertTrue(
                Filament::getPanel($id)->isSidebarCollapsibleOnDesktop(),
                "{$id} sidebar harus bisa dilipat",
            );
        }
    }

    public function test_the_theme_addresses_elements_that_exist(): void
    {
        $theme = $this->rules($this->theme());

        /*
         * `.fi-topbar > nav` is the shape of mistake this file is most prone
         * to: it reads plausibly, it compiles, and it styles nothing. There is
         * no nav inside the topbar — measured in the browser, `.fi-topbar`
         * holds `.fi-topbar-start` and `.fi-topbar-end` directly — and the
         * previous theme carried that dead selector for a month.
         *
         * Same for the header band: `.fi-header` sits inside
         * `.fi-page-header-main-ctn`, not directly inside `.fi-page`.
         */
        $this->assertStringNotContainsString('.fi-topbar > nav', $theme);
        $this->assertStringNotContainsString('.fi-page > .fi-header', $theme);
    }
}
