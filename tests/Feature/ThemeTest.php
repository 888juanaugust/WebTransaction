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
     * Filament paints solid buttons and active states with shade 600, so the
     * company blue has to sit exactly there — naming it elsewhere in the ramp
     * produces a button that is not the company's colour at all.
     */
    public function test_the_company_blue_sits_on_the_shade_filament_paints_buttons_with(): void
    {
        // #073185 — the logo's own blue — in oklch, as Filament's constants are.
        $this->assertSame('oklch(0.348 0.148 262.160)', BrandColors::Blue[600]);
    }

    /**
     * The panel and the public site draw from two separate declarations of the
     * same palette, and they have drifted before. The logo's blue has to be in
     * both or the shopfront and the panel are subtly different companies.
     */
    public function test_the_public_site_uses_the_same_blue_as_the_panel(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--color-brand-600: #073185;', $css);
        $this->assertStringContainsString(
            '--wt-blue: #073185',
            file_get_contents(resource_path('css/filament/admin/theme.css')),
        );
    }

    public function test_the_palette_is_blue_and_red(): void
    {
        $palette = BrandColors::panel();

        $this->assertSame(BrandColors::Blue, $palette['primary']);
        $this->assertSame(BrandColors::Blue, $palette['info']);
        $this->assertSame(BrandColors::Red, $palette['danger']);
    }

    /** "Dasbor" is the dictionary word; "Dashboard" is what staff say. */
    public function test_the_dashboard_is_called_dashboard(): void
    {
        $this->assertSame('Dashboard', __('filament-panels::pages/dashboard.title'));
    }
}
