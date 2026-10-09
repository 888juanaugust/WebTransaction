<?php

namespace Tests\Feature\Client\Site;

use Tests\TestCase;

/** The language switch: Indonesian by default, English one link away and then remembered, never a redirect off this host. */
class SiteLanguageTest extends TestCase
{
    public function test_the_site_speaks_indonesian_by_default_and_the_copy_follows(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<html lang="id"', false)
            ->assertSee('Distributor grosir suku cadang otomotif')
            ->assertSee('Tentang kami')->assertSee('Kategori produk')
            ->assertDontSee('Wholesale distributor')->assertDontSee('Product categories');
    }

    public function test_english_is_one_link_away_and_then_remembered(): void
    {
        $this->from('/tentang-kami')->get('/bahasa/en')
            ->assertRedirect(url('/tentang-kami'))
            ->assertCookie('bahasa', 'en');

        $this->withCookie('bahasa', 'en')->get('/')->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('Wholesale distributor of automotive spare parts')
            ->assertSee('About us')->assertSee('Product categories')
            ->assertDontSee('Distributor grosir')->assertDontSee('Kategori produk');

        $this->withCookie('bahasa', 'en')->get('/kontak')->assertOk()->assertSee('Business hours')->assertSee('Monday to Friday');
    }

    public function test_the_switch_offers_only_the_other_language(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('href="'.url('/bahasa/en').'"', false)
            ->assertDontSee('href="'.url('/bahasa/id').'"', false);
        $this->withCookie('bahasa', 'en')->get('/')->assertOk()
            ->assertSee('href="'.url('/bahasa/id').'"', false)
            ->assertDontSee('href="'.url('/bahasa/en').'"', false);
    }

    public function test_an_unknown_language_is_refused_and_a_bad_cookie_is_ignored(): void
    {
        $this->get('/bahasa/fr')->assertNotFound();
        $this->withCookie('bahasa', 'fr')->get('/')->assertOk()->assertSee('<html lang="id"', false);
    }

    public function test_the_switch_never_redirects_off_this_host(): void
    {
        $this->from('https://evil.example/phish')->get('/bahasa/en')->assertRedirect(url('/'));
        $this->from(url('/').'.evil.example/')->get('/bahasa/en')->assertRedirect(url('/'));
        $this->from('/mitra')->get('/bahasa/id')->assertRedirect(url('/mitra'))->assertCookie('bahasa', 'id');
    }
}
