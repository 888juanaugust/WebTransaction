<?php

namespace Tests\Feature\Client\Site;

use App\Client\Site\Http\SiteContentSecurityPolicy;
use App\Client\Site\Sitemap;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\Branch;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The public site: every page open, no price anywhere, the legal pages Indonesian, the strict policy, robots and the sitemap. */
class SiteRoutesTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            'home' => ['/'], 'about' => ['/tentang-kami'], 'partners' => ['/mitra'], 'roadmap' => ['/rencana-pengembangan'],
            'contact' => ['/kontak'], 'privacy' => ['/kebijakan-privasi'], 'terms' => ['/syarat-penjualan'], 'sign in' => ['/masuk'],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_page_renders_without_a_login_in_indonesian_with_its_canonical_and_schema(string $path): void
    {
        $response = $this->get($path)->assertOk()
            ->assertSee('<html lang="id"', false)
            ->assertSee('rel="canonical" href="'.url($path === '/' ? '' : $path).'"', false)
            ->assertSee('"@context":"https://schema.org"', false)
            ->assertSee(config('app.name'));
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression('/\bRp\b|\bIDR\b/', $html, "{$path} shows a price");
        $this->assertStringNotContainsString('<script nonce', $html, 'no inline script: the site runs under a strict policy');
        $this->assertStringNotContainsString(' style="', $html, 'no inline style');
        $this->assertStringNotContainsStringIgnoringCase('livewire', $html, 'nothing the site does not use is loaded');
    }

    public function test_the_root_is_the_site_and_no_longer_a_redirect_to_the_panel(): void
    {
        $this->get('/')->assertOk()->assertSee('<main', false);
    }

    public function test_the_site_carries_a_second_stricter_content_policy_the_panels_do_not(): void
    {
        $policies = $this->get('/')->assertOk()->headers->all('content-security-policy');
        $this->assertCount(2, $policies, 'the base policy and the site policy, both enforced');
        $this->assertContains(SiteContentSecurityPolicy::POLICY, $policies);
        $this->assertStringNotContainsString("'unsafe-inline'", SiteContentSecurityPolicy::POLICY);

        $this->assertCount(1, $this->get('/admin/login')->headers->all('content-security-policy'));
    }

    public function test_the_legal_pages_stay_indonesian_whatever_the_cookie_says(): void
    {
        app(Preferensi::class)->set(PreferensiKey::CreditNoticeDays, 120);
        app(Preferensi::class)->set(PreferensiKey::CreditFreezeDays, 150);

        $privacy = $this->withCookie('bahasa', 'en')->get('/kebijakan-privasi')->assertOk()
            ->assertSee('<html lang="id"', false)->assertSee('Kebijakan Privasi')->assertSee('UU PDP')->assertSee('bahasa')
            ->assertDontSee('Privacy policy')->assertDontSee('Sign in');
        $this->assertStringContainsString('Kebijakan privasi', $privacy->getContent(), 'the chrome is Indonesian too');

        $this->withCookie('bahasa', 'en')->get('/syarat-penjualan')->assertOk()
            ->assertSee('<html lang="id"', false)->assertSee('Syarat Penjualan')
            ->assertSee('120 hari')->assertSee('150 hari', false)->assertSee('2% per bulan')
            ->assertDontSee('Terms of sale');
    }

    public function test_the_contact_page_lists_the_active_branches_with_a_map_link(): void
    {
        Branch::query()->create(['name' => 'Jakarta', 'code' => 'JKT', 'used_all_user' => true, 'is_active' => true, 'is_default' => true, 'address' => 'Jl. Contoh No. 1, Jakarta', 'phone_number' => '+62 21 555 0100', 'latitude' => -6.2, 'longitude' => 106.816]);
        Branch::query()->create(['name' => 'Surabaya', 'code' => 'SBY', 'used_all_user' => false, 'is_active' => true, 'address' => 'Jl. Pahlawan 2, Surabaya']);
        Branch::query()->create(['name' => 'Closed', 'code' => 'OLD', 'used_all_user' => false, 'is_active' => false]);

        $this->get('/kontak')->assertOk()
            ->assertSee('Jl. Contoh No. 1, Jakarta')->assertSee('+62 21 555 0100')
            ->assertSee('https://www.google.com/maps?q=-6.2', false)
            ->assertSee('Surabaya')->assertDontSee('Closed');
        $this->get('/')->assertOk()->assertSee('Surabaya')->assertDontSee('Closed');
    }

    public function test_the_sign_in_page_offers_the_portal_and_the_panel(): void
    {
        $this->get('/masuk')->assertOk()
            ->assertSee('href="'.url('/portal/login').'"', false)
            ->assertSee('href="'.url('/admin/login').'"', false);
    }

    public function test_robots_blocks_the_panels_and_the_sitemap_lists_the_public_pages_only(): void
    {
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin')->assertSee('Disallow: /portal')->assertSee('Sitemap: '.url('/sitemap.xml'));

        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        foreach (Sitemap::ROUTES as $name) {
            $sitemap->assertSee('<loc>'.route($name).'</loc>', false);
        }
        $this->assertSame(count(Sitemap::ROUTES), substr_count($sitemap->getContent(), '<loc>'));
        $sitemap->assertDontSee('/admin')->assertDontSee('/portal');
    }
}
