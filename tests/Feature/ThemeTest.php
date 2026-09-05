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
     * The shopfront still wears the logo's blue.
     *
     * This test used to assert that the panel wore it too, and that was the
     * right guard while both did — the two palettes are declared separately
     * and had drifted before. The FixFlow design system (2026-09) moved the
     * **panels** to indigo and left the public site and every printed
     * document on #073185, so the guard now covers what is still shared: the
     * shopfront's own declaration, and the fact that the logo blue is still
     * defined somewhere the panels can be put back onto.
     *
     * Editing this test was the deliberate act the change required, which is
     * the point of having written it down in the first place.
     */
    public function test_the_shopfront_still_wears_the_logo_blue(): void
    {
        $this->assertStringContainsString(
            '--color-brand-600: #073185;',
            file_get_contents(resource_path('css/app.css')),
        );

        // Still declared, so the panels are one line from going back.
        $this->assertSame('oklch(0.348 0.148 262.160)', BrandColors::Blue[600]);
    }

    /**
     * The panels lead with indigo, and red and green keep their jobs.
     *
     * `danger` and `success` are not brand colours here: red means an overdue
     * faktur or short stock, green is the "Lunas" finance scans a list for.
     * A redesign that swept either into the primary would look tidier and
     * read worse, so both are asserted to be something other than it.
     */
    public function test_the_panel_palette_leads_with_indigo(): void
    {
        $palette = BrandColors::panel();

        $this->assertSame(BrandColors::Indigo, $palette['primary']);
        $this->assertSame(BrandColors::Indigo, $palette['info']);
        $this->assertSame(BrandColors::Red, $palette['danger']);
        $this->assertNotSame($palette['primary'], $palette['success']);
    }

    /** "Dasbor" is the dictionary word; "Dashboard" is what staff say. */
    public function test_the_dashboard_is_called_dashboard(): void
    {
        $this->assertSame('Dashboard', __('filament-panels::pages/dashboard.title'));
    }
}
