<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Branding;
use Tests\TestCase;

/**
 * The company mark, and what happens when there isn't one.
 *
 * Every surface that shows a logo — the public header, both panel sidebars, the
 * surat jalan letterhead — asks Branding first. The failure this guards against
 * is narrow and embarrassing: a configured path pointing at a file that was
 * never deployed renders a broken image on the company's own shopfront, and it
 * would look fine on the machine where the file happens to exist.
 */
class BrandingTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['images/logo-test.svg', 'logo-test-root.svg'] as $path) {
            if (file_exists(public_path($path))) {
                unlink(public_path($path));
            }
        }

        parent::tearDown();
    }

    private function writeLogo(string $path): void
    {
        $full = public_path($path);

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }

        file_put_contents($full, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    }

    public function test_no_logo_is_configured_by_default(): void
    {
        config()->set('perusahaan.logo', null);

        $this->assertNull(Branding::logoUrl());
        $this->assertFalse(Branding::hasLogo());
    }

    /**
     * The one that matters. A path set for a file nobody deployed must not
     * become an <img> tag.
     */
    public function test_a_configured_path_to_a_missing_file_is_ignored(): void
    {
        config()->set('perusahaan.logo', 'images/does-not-exist.svg');

        $this->assertNull(Branding::logoUrl(), 'a missing file must not render as a broken image');
        $this->assertFalse(Branding::hasLogo());
    }

    public function test_a_real_file_produces_a_url(): void
    {
        $this->writeLogo('images/logo-test.svg');
        config()->set('perusahaan.logo', 'images/logo-test.svg');

        $this->assertTrue(Branding::hasLogo());
        $this->assertStringEndsWith('/images/logo-test.svg', (string) Branding::logoUrl());
    }

    /** A leading slash is the obvious way to write it and must not break. */
    public function test_a_leading_slash_is_tolerated(): void
    {
        $this->writeLogo('logo-test-root.svg');
        config()->set('perusahaan.logo', '/logo-test-root.svg');

        $this->assertTrue(Branding::hasLogo());
    }

    public function test_an_empty_string_counts_as_no_logo(): void
    {
        config()->set('perusahaan.logo', '   ');

        $this->assertFalse(Branding::hasLogo());
    }

    /** With no logo the public header still shows the company, in words. */
    public function test_the_public_header_falls_back_to_the_wordmark(): void
    {
        config()->set('perusahaan.logo', null);

        $this->get('/')
            ->assertOk()
            ->assertSee(config('perusahaan.nama_singkat'))
            ->assertDontSee('<img src="http://localhost/images', escape: false);
    }

    public function test_the_wordmark_is_the_short_company_name(): void
    {
        $this->assertSame(config('perusahaan.nama_singkat'), Branding::wordmark());
    }
}
