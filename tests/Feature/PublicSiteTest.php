<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Support\Perusahaan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public site is open and indexed. Three things must hold: every page
 * renders without a login, no page shows a price, and the language split is
 * respected — the shopfront is English, and the two legal pages, which are
 * instruments under Indonesian law, are Bahasa Indonesia and say so.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string}> */
    public static function halamanPublik(): array
    {
        return [
            'beranda' => ['/'],
            'tentang' => ['/tentang-kami'],
            'mitra' => ['/mitra'],
            'rencana' => ['/rencana-pengembangan'],
            'kontak' => ['/kontak'],
            'masuk' => ['/masuk'],
        ];
    }

    /** The legal pages: Indonesian law, Indonesian text. */
    public static function halamanHukum(): array
    {
        return [
            'privasi' => ['/kebijakan-privasi'],
            'syarat' => ['/syarat-penjualan'],
        ];
    }

    #[DataProvider('halamanPublik')]
    public function test_public_pages_render_without_logging_in(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee(config('perusahaan.nama'), escape: false);
    }

    // --- language split -----------------------------------------------------

    public function test_the_site_speaks_bahasa_indonesia_by_default(): void
    {
        /*
         * An Indonesian wholesaler introducing itself to Indonesian workshops.
         * English exists (below) for the overseas suppliers and partners who
         * also read the site, and it is a choice they make once — not a guess
         * from their browser, which would hand an Indonesian on an English-
         * locale laptop the wrong language with no obvious way back.
         */
        $response = $this->get('/')->assertOk();

        $response->assertSee('<html lang="id"', escape: false);
        $response->assertDontSee('<html lang="en"', escape: false);

        $response->assertSee(Perusahaan::text('ringkasan'), escape: false);
        $response->assertSee('Tentang perusahaan');
        $response->assertSee('Merk yang kami bawa');
        $response->assertSee('Kategori produk');
        $response->assertSee('Jadi pelanggan dalam tiga langkah');
        $response->assertSee('Masuk ke akun Anda');

        // And none of the English chrome bleeds through.
        foreach (['About the company', 'Brands we carry', 'Sign in to your account'] as $english) {
            $response->assertDontSee($english);
        }
    }

    public function test_english_is_one_link_away_and_then_remembered(): void
    {
        // The switch is a plain link — no form, no script — that sets a
        // year-long cookie and sends the visitor back where they were.
        $this->from('/tentang-kami')->get('/bahasa/en')
            ->assertRedirect(url('/tentang-kami'))
            ->assertCookie('bahasa', 'en');

        $response = $this->withCookie('bahasa', 'en')->get('/')->assertOk();

        $response->assertSee('<html lang="en"', escape: false);
        $response->assertSee('About the company');
        $response->assertSee('Brands we carry');
        $response->assertSee('Product categories');
        $response->assertSee('Become a customer in three steps');
        $response->assertSee('Sign in to your account');

        foreach (['Tentang perusahaan', 'Merk yang kami bawa', 'Jadi pelanggan dalam tiga langkah'] as $indonesian) {
            $response->assertDontSee($indonesian);
        }

        // The company's own copy follows the switch too, not just the chrome.
        $response->assertSee('Wholesale distributor of automotive spare parts');
    }

    public function test_the_switch_offers_only_the_other_language(): void
    {
        // A toggle that shows the language you are already reading is a
        // button that does nothing. One link, to the other side.
        $this->get('/')->assertOk()
            ->assertSee('href="'.url('/bahasa/en').'"', escape: false)
            ->assertDontSee('href="'.url('/bahasa/id').'"', escape: false);

        $this->withCookie('bahasa', 'id')->get('/')->assertOk()
            ->assertSee('href="'.url('/bahasa/en').'"', escape: false);

        $this->withCookie('bahasa', 'en')->get('/')->assertOk()
            ->assertSee('href="'.url('/bahasa/id').'"', escape: false)
            ->assertDontSee('href="'.url('/bahasa/en').'"', escape: false);
    }

    public function test_an_unknown_language_is_refused_and_a_bad_cookie_is_ignored(): void
    {
        $this->get('/bahasa/fr')->assertNotFound();

        // A cookie somebody edited falls back to the default rather than
        // asking Laravel for a language that has no files.
        $this->withCookie('bahasa', 'xx')->get('/')->assertOk()
            ->assertSee('<html lang="id"', escape: false);
    }

    public function test_the_switch_never_redirects_off_this_host(): void
    {
        // The referer decides where "back" is, and a referer is whatever the
        // previous page said it was. Off-host means home, not there.
        $this->from('https://evil.example/phish')->get('/bahasa/en')
            ->assertRedirect(url('/'));
    }

    #[DataProvider('halamanPublik')]
    public function test_every_other_page_follows_the_choice(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee('<html lang="id"', escape: false);

        $this->withCookie('bahasa', 'en')->get($path)
            ->assertOk()
            ->assertSee('<html lang="en"', escape: false)
            ->assertDontSee('<html lang="id"', escape: false);
    }

    /**
     * The legal pages are the one exception: written for Indonesian law, in
     * Indonesian, and declared as such whatever the visitor chose — so a
     * screen reader does not read Bahasa with English pronunciation.
     */
    #[DataProvider('halamanHukum')]
    public function test_the_legal_pages_stay_in_bahasa(string $path): void
    {
        foreach ([null, 'id', 'en'] as $bahasa) {
            $request = $bahasa === null ? $this : $this->withCookie('bahasa', $bahasa);

            $request->get($path)
                ->assertOk()
                ->assertSee('<html lang="id"', escape: false)
                ->assertDontSee('<html lang="en"', escape: false);
        }
    }

    public function test_the_about_page_uses_the_company_profile(): void
    {
        $response = $this->get('/tentang-kami')->assertOk();

        foreach (Perusahaan::list('profil') as $paragraf) {
            $response->assertSee($paragraf, escape: false);
        }

        $response->assertSee(Perusahaan::text('tagline'), escape: false);
    }

    // --- content ------------------------------------------------------------

    public function test_the_home_page_shows_the_company_profile(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        foreach (config('perusahaan.merk') as $merk) {
            $response->assertSee($merk);
        }

        foreach (Perusahaan::records('kategori') as $kategori) {
            $response->assertSee($kategori['nama']);
            $response->assertSee($kategori['deskripsi'], escape: false);
        }
    }

    public function test_the_home_page_lists_joint_venture_partners(): void
    {
        $response = $this->get('/');

        foreach (Perusahaan::records('mitra') as $mitra) {
            $response->assertSee($mitra['nama'], escape: false);
        }
    }

    public function test_the_partners_page_lists_every_partner(): void
    {
        $response = $this->get('/mitra')->assertOk();

        foreach (Perusahaan::records('mitra') as $mitra) {
            $response->assertSee($mitra['nama'], escape: false);
            $response->assertSee($mitra['deskripsi'], escape: false);
        }
    }

    public function test_the_future_works_page_lists_the_roadmap(): void
    {
        $response = $this->get('/rencana-pengembangan')->assertOk();

        // Through the helper, not config: the roadmap is bilingual pairs now.
        foreach (Perusahaan::records('rencana') as $item) {
            $response->assertSee($item['judul'], escape: false);
        }
    }

    public function test_the_contact_page_shows_contact_details(): void
    {
        $this->get('/kontak')
            ->assertOk()
            ->assertSee(config('perusahaan.kontak.email'))
            ->assertSee(config('perusahaan.kontak.telepon'), escape: false)
            ->assertSee(config('perusahaan.kontak.alamat'), escape: false)
            ->assertSee(Perusahaan::jamOperasional(), escape: false);
    }

    public function test_the_login_page_offers_both_doors(): void
    {
        $this->get('/masuk')
            ->assertOk()
            ->assertSee('Portal Pelanggan')
            ->assertSee('Panel Admin')
            ->assertSee('/portal/login', escape: false)
            ->assertSee('/admin/login', escape: false);

        $this->withCookie('bahasa', 'en')->get('/masuk')
            ->assertOk()
            ->assertSee('Customer Portal')
            ->assertSee('Admin Panel');
    }

    /**
     * Public price display is out of scope for v1, and wholesale pricing is
     * per customer — a price on a public page is a commercial leak, not just
     * a UI slip.
     */
    public function test_no_public_page_shows_a_price(): void
    {
        Company::factory()->create();

        $version = PriceListVersion::factory()->published()->create();
        Product::factory()->create(['kode' => 'YH-9001']);
        PriceListItem::factory()->create([
            'version_id' => $version->id,
            'kode' => 'YH-9001',
            'harga' => 987_654,
        ]);

        foreach (array_column(self::halamanPublik(), 0) as $path) {
            $body = $this->get($path)->assertOk()->getContent();

            $this->assertStringNotContainsString('987.654', $body, "Price leaked on {$path}");
            $this->assertStringNotContainsString('987654', $body, "Price leaked on {$path}");
            $this->assertStringNotContainsString('Rp ', $body, "Rupiah figure rendered on {$path}");
        }
    }

    // --- launch plumbing: what crawlers are told ---------------------------

    public function test_robots_txt_blocks_the_panels_and_names_the_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /portal')
            // The sitemap protocol requires an absolute URL, which is the
            // whole reason robots.txt is a route and not a static file.
            ->assertSee('Sitemap: '.route('sitemap'), escape: false);
    }

    public function test_the_sitemap_lists_every_public_page_and_nothing_else(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));

        foreach (['/', '/tentang-kami', '/mitra', '/rencana-pengembangan',
            '/kontak', '/masuk', '/kebijakan-privasi', '/syarat-penjualan'] as $path) {
            $response->assertSee('<loc>'.url($path).'</loc>', escape: false);
        }

        $response->assertDontSee('/admin');
        $response->assertDontSee('/portal');
    }

    public function test_every_page_carries_canonical_and_organization_schema(): void
    {
        $this->get('/tentang-kami')
            ->assertOk()
            ->assertSee('<link rel="canonical"', escape: false)
            ->assertSee('application/ld+json', escape: false)
            ->assertSee('"@type":"Organization"', escape: false);
    }

    public function test_the_mobile_menu_button_is_wired_to_its_panel(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="tombol-menu"', escape: false)
            ->assertSee('aria-controls="menu-seluler"', escape: false)
            ->assertSee('id="menu-seluler"', escape: false);
    }
}
