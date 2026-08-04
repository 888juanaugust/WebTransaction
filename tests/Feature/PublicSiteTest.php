<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public site is open and indexed. Two things must hold on every page:
 * it renders without a login, and it never shows a price.
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

    #[DataProvider('halamanPublik')]
    public function test_public_pages_render_without_logging_in(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee(config('perusahaan.nama'), escape: false);
    }

    public function test_the_home_page_shows_the_company_profile(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(config('perusahaan.ringkasan'), escape: false);

        // The brands and categories the business actually carries.
        foreach (config('perusahaan.merk') as $merk) {
            $response->assertSee($merk);
        }

        foreach (config('perusahaan.kategori') as $kategori) {
            $response->assertSee($kategori['nama']);
        }
    }

    public function test_the_home_page_lists_joint_venture_partners(): void
    {
        $response = $this->get('/');

        foreach (config('perusahaan.mitra') as $mitra) {
            $response->assertSee($mitra['nama'], escape: false);
        }
    }

    public function test_the_partners_page_lists_every_partner(): void
    {
        $response = $this->get('/mitra')->assertOk();

        foreach (config('perusahaan.mitra') as $mitra) {
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
            ->assertSee(config('perusahaan.kontak.alamat'), escape: false);
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
