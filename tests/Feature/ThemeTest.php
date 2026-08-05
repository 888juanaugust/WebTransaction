<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\BrandColors;
use Tests\TestCase;

/**
 * Guards on the company theme.
 *
 * These are cheap source-level assertions rather than rendering checks, and
 * they exist because both failures they cover were shipped once already and
 * neither showed up in any other test.
 */
class ThemeTest extends TestCase
{
    private function panelTheme(): string
    {
        return file_get_contents(resource_path('css/filament/admin/theme.css'));
    }

    /**
     * The stylesheet with CSS comments stripped.
     *
     * These guards are about the rules, not the prose. The stylesheet's own
     * comments explain why prefers-color-scheme is the wrong signal here, so
     * matching the raw file would fail against its own documentation.
     */
    private function panelThemeRules(): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $this->panelTheme());
    }

    /**
     * Filament toggles dark mode with a `.dark` class on <html>. It does not
     * follow the OS once a user has chosen.
     *
     * Keying off prefers-color-scheme meant a user on a light laptop who
     * switched the panel to dark kept the light overrides — white cards on a
     * near-black page, navy headings on near-black. The two signals only
     * appeared to agree because the OS and the toggle usually match.
     */
    public function test_dark_mode_keys_off_filaments_class_not_the_os(): void
    {
        $rules = $this->panelThemeRules();

        $this->assertStringNotContainsString(
            'prefers-color-scheme',
            $rules,
            'Panel dark mode must follow Filament\'s .dark class, not the OS preference.'
        );

        $this->assertStringContainsString('html.dark', $rules);
    }

    /**
     * Every light-mode override must be scoped away from dark, or it paints
     * over Filament's dark chrome.
     */
    public function test_light_overrides_are_scoped_out_of_dark_mode(): void
    {
        $this->assertStringContainsString('html:not(.dark)', $this->panelThemeRules());
    }

    /**
     * The near-white blue tint is a light-mode value. Reusing it on a dark
     * ground drew a glaring bar across every widget heading.
     */
    public function test_the_light_blue_tint_is_not_used_in_dark_mode(): void
    {
        // Isolate the dark blocks and check the light tint variable is absent.
        $darkSections = [];
        if (preg_match_all('/html\.dark\s*\{(.*?)\n\}/s', $this->panelThemeRules(), $matches)) {
            $darkSections = $matches[1];
        }

        $this->assertNotEmpty($darkSections, 'Expected at least one html.dark block.');

        foreach ($darkSections as $section) {
            $this->assertStringNotContainsString(
                '--wt-blue-tint',
                $section,
                'The light blue tint is nearly white and must not be used on a dark ground.'
            );
        }
    }

    /** The public site is deliberately light-only; say so to the browser. */
    public function test_the_public_site_declares_a_light_colour_scheme(): void
    {
        $this->assertStringContainsString(
            'color-scheme: light',
            file_get_contents(resource_path('css/app.css'))
        );

        $this->get('/')->assertOk()->assertSee('name="color-scheme"', escape: false);
    }

    /**
     * Filament paints solid buttons and active states with shade 600, so a
     * brand colour has to sit exactly there — naming it elsewhere in the ramp
     * produces a button that is not the brand colour at all.
     */
    public function test_the_brand_colours_sit_on_the_shade_filament_paints_buttons_with(): void
    {
        // #2B3467 and #EB455F in oklch, as Filament's own constants are.
        $this->assertSame('oklch(0.345 0.089 273.324)', BrandColors::Navy[600]);
        $this->assertSame('oklch(0.636 0.201 16.300)', BrandColors::Coral[600]);
    }

    /**
     * Powder blue is the exception, deliberately. It is a *tint* (L 0.864), so
     * at 600 it would be a pale button with white text on it. It sits at 200,
     * which is where the surfaces and badges that use it actually draw from.
     */
    public function test_the_powder_tint_sits_where_a_tint_belongs(): void
    {
        $this->assertSame('oklch(0.864 0.040 235.188)', BrandColors::Powder[200]);
    }

    public function test_the_panel_palette_uses_the_brand_ramps(): void
    {
        $palette = BrandColors::panel();

        $this->assertSame(BrandColors::Navy, $palette['primary']);
        $this->assertSame(BrandColors::Powder, $palette['info']);
        $this->assertSame(BrandColors::Coral, $palette['danger']);
    }

    /** "Dasbor" is the dictionary word; "Dashboard" is what staff say. */
    public function test_the_dashboard_is_called_dashboard(): void
    {
        $this->assertSame('Dashboard', __('filament-panels::pages/dashboard.title'));
    }
}
