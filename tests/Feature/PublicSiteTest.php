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
 * respected — every page is Bahasa Indonesia, including the home page.
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

    /** Every public page except the home page. */
    public static function halamanBahasa(): array
    {
        return [
            'tentang' => ['/tentang-kami'],
            'mitra' => ['/mitra'],
            'rencana' => ['/rencana-pengembangan'],
            'kontak' => ['/kontak'],
            'masuk' => ['/masuk'],
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

    public function test_the_home_page_is_in_bahasa_like_every_other_page(): void
    {
        $response = $this->get('/')->assertOk();

        // Declared for search engines and screen readers, not just visually.
        $response->assertSee('<html lang="id"', escape: false);
        $response->assertDontSee('<html lang="en"', escape: false);

        $response->assertSee(Perusahaan::text('ringkasan'), escape: false);
        $response->assertSee('Tentang perusahaan kami');
        $response->assertSee('Merk yang kami bawa');
        $response->assertSee('Kategori produk');
        $response->assertSee('Mitra usaha patungan');
        $response->assertSee('Masuk ke akun Anda');
    }

    /**
     * The site was briefly bilingual, with an English home page in front of
     * Indonesian inner pages. That meant nav labels changed language depending
     * on which page you stood on, and one sentence had two copies in config to
     * keep in step. This fails if any of it comes back.
     */
    public function test_no_english_chrome_survives_on_the_home_page(): void
    {
        $response = $this->get('/')->assertOk();

        foreach (['Sign in', 'Home', 'About Us', 'Partners', 'Roadmap', 'Contact',
            'Brands we carry', 'Product categories'] as $english) {
            $response->assertDontSee($english);
        }
    }

    #[DataProvider('halamanBahasa')]
    public function test_every_other_page_is_in_bahasa(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee('<html lang="id"', escape: false)
            ->assertDontSee('<html lang="en"', escape: false);
    }

    public function test_the_about_page_uses_the_indonesian_profile(): void
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

        foreach (config('perusahaan.rencana') as $item) {
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
            ->assertSee(Perusahaan::text('kontak.jam_operasional'), escape: false);
    }

    public function test_the_login_page_offers_both_doors(): void
    {
        $this->get('/masuk')
            ->assertOk()
            ->assertSee('Portal Pelanggan')
            ->assertSee('Panel Admin')
            ->assertSee('/portal/login', escape: false)
            ->assertSee('/admin/login', escape: false);
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
}
