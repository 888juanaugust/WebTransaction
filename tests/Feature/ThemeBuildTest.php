<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The built assets carry the design system: the theme and the self-hosted fonts. */
class ThemeBuildTest extends TestCase
{
    public function test_the_manifest_contains_the_theme_and_the_geist_fonts(): void
    {
        $manifest = public_path('build/manifest.json');
        if (! file_exists($manifest)) {
            $this->markTestSkipped('Assets not built; run npm run build.');
        }

        $json = file_get_contents($manifest);
        $this->assertStringContainsString('resources/css/filament/admin/theme.css', $json);

        $assets = collect(scandir(public_path('build/assets')));
        $this->assertTrue($assets->contains(fn ($f) => str_starts_with($f, 'geist-latin-wght-normal')), 'Geist is self-hosted');
        $this->assertTrue($assets->contains(fn ($f) => str_starts_with($f, 'geist-mono-latin-wght-normal')), 'Geist Mono is self-hosted');
    }

    public function test_the_workspace_motion_is_bundled_not_fetched(): void
    {
        $manifest = public_path('build/manifest.json');
        if (! file_exists($manifest)) {
            $this->markTestSkipped('Assets not built; run npm run build.');
        }

        $this->assertStringContainsString('resources/js/workspace-motion.js', file_get_contents($manifest));
        $this->assertStringContainsString("@vite('resources/js/workspace-motion.js')", file_get_contents(resource_path('views/filament/shell/workspace.blade.php')));
        $this->assertStringNotContainsString('cdn', strtolower(file_get_contents(resource_path('js/workspace-motion.js'))));
    }

    public function test_no_third_party_font_is_loaded(): void
    {
        $this->assertStringNotContainsString('bunny', file_get_contents(base_path('vite.config.js')));
        $this->assertStringNotContainsString('fonts.googleapis', file_get_contents(base_path('resources/css/filament/admin/theme.css')));
    }
}
