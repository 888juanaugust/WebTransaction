<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Resources\Regions\Pages\EditRegion;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The three things the owner's revision list added to the shopfront: a
 * promo carousel, a cookie-and-location notice, and a nearest-branch finder.
 *
 * All three run under the enforced CSP with no library, so most of what is
 * worth pinning is that they degrade honestly — no promo means no carousel,
 * a branch without coordinates is listed but never "nearest", and the
 * visitor's position is never asked for on load.
 */
class PublicSiteFeaturesTest extends TestCase
{
    use RefreshDatabase;

    // --- promo carousel ------------------------------------------------------

    public function test_the_landing_page_has_no_carousel_until_there_is_a_promotion(): void
    {
        config(['perusahaan.promo' => []]);

        $this->get('/')->assertOk()
            ->assertDontSee('aria-roledescription="carousel"', escape: false);
    }

    public function test_an_active_promotion_renders_as_a_slide_from_our_own_server(): void
    {
        config(['perusahaan.promo' => [
            ['judul' => 'Diskon bearing September', 'teks' => 'Sepuluh persen untuk pesanan di atas satu dus.',
                'gambar' => 'promo/bearing.jpg', 'tautan' => 'https://example.test/promo', 'aktif' => true],
            ['judul' => 'Promo lama', 'teks' => '', 'gambar' => 'promo/lama.jpg', 'tautan' => '', 'aktif' => false],
        ]]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('aria-roledescription="carousel"', escape: false);
        $response->assertSee('Diskon bearing September');
        $response->assertSee('Sepuluh persen untuk pesanan di atas satu dus.');
        // The image is ours, on our disk — never a third-party URL.
        $response->assertSee('/storage/promo/bearing.jpg', escape: false);
        $response->assertSee('alt="Diskon bearing September"', escape: false);
        $response->assertSee('href="https://example.test/promo"', escape: false);

        // The inactive one is stored, not shown.
        $response->assertDontSee('Promo lama');
        $response->assertDontSee('promo/lama.jpg');
    }

    public function test_one_slide_gets_no_arrows_and_no_dots(): void
    {
        config(['perusahaan.promo' => [
            ['judul' => 'Satu saja', 'teks' => '', 'gambar' => 'promo/satu.jpg', 'tautan' => '', 'aktif' => true],
        ]]);

        // Asserted on the markup the buttons would be, not on their attribute
        // names — the script mentions those whether or not the buttons exist.
        $this->get('/')->assertOk()
            ->assertSee('Satu saja')
            ->assertDontSee('class="promo-arrow', escape: false)
            ->assertDontSee('role="tablist"', escape: false);
    }

    public function test_the_carousel_script_carries_the_csp_nonce(): void
    {
        config(['perusahaan.promo' => [
            ['judul' => 'A', 'teks' => '', 'gambar' => 'promo/a.jpg', 'tautan' => '', 'aktif' => true],
            ['judul' => 'B', 'teks' => '', 'gambar' => 'promo/b.jpg', 'tautan' => '', 'aktif' => true],
        ]]);

        $response = $this->get('/')->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');

        preg_match("/'nonce-([^']+)'/", (string) $csp, $m);
        $this->assertNotEmpty($m[1] ?? '', 'the public CSP names a nonce');

        // Every inline script on the page — the carousel's included — carries it,
        // or the browser refuses to run it.
        $this->assertStringContainsString('getElementById(\'promo-track\')', $response->getContent());
        preg_match_all('/<script(?![^>]*type="application\/(ld\+)?json")[^>]*>/', $response->getContent(), $tags);

        foreach ($tags[0] as $tag) {
            if (str_contains($tag, 'src=')) {
                continue;
            }
            $this->assertStringContainsString("nonce=\"{$m[1]}\"", $tag, "inline script without the nonce: {$tag}");
        }
    }

    // --- cookie and location notice ------------------------------------------

    public function test_the_notice_is_on_every_public_page_with_the_one_honest_control(): void
    {
        foreach (['/', '/tentang-kami', '/kontak', '/masuk'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('id="persetujuan"', escape: false)
                ->assertSee('id="persetujuan-mengerti"', escape: false)
                ->assertSee(route('publik.privasi').'#cookie', escape: false)
                ->assertSee(route('publik.kontak').'#cabang', escape: false);
        }
    }

    public function test_the_notice_speaks_the_visitors_language(): void
    {
        $this->get('/')->assertOk()->assertSee('Cookie dan lokasi');
        $this->withCookie('bahasa', 'en')->get('/')->assertOk()->assertSee('Cookies and location');
    }

    // --- nearest branch ----------------------------------------------------------

    public function test_the_contact_page_lists_active_branches_and_hands_the_browser_their_coordinates(): void
    {
        Region::query()->delete();
        Region::factory()->create(['kode' => 'SBY', 'nama' => 'Cabang Surabaya', 'alamat' => 'Jl. Rungkut 1',
            'telepon' => '031-000', 'lintang' => -7.257472, 'bujur' => 112.752090, 'aktif' => true]);
        Region::factory()->create(['kode' => 'JKT', 'nama' => 'Cabang Jakarta', 'alamat' => 'Jl. Sudirman 1',
            'telepon' => '021-000', 'lintang' => null, 'bujur' => null, 'aktif' => true]);
        Region::factory()->create(['kode' => 'OLD', 'nama' => 'Cabang Tutup', 'aktif' => false]);

        $response = $this->get('/kontak')->assertOk();

        // Both live branches are listed; the closed one is not.
        $response->assertSee('Cabang Surabaya')->assertSee('Jl. Rungkut 1');
        $response->assertSee('Cabang Jakarta');
        $response->assertDontSee('Cabang Tutup');

        // Only the branch with coordinates can be "nearest": the data block
        // carries Surabaya and leaves Jakarta out rather than sending nulls.
        $response->assertSee('id="cabang-data"', escape: false);
        preg_match('/<script type="application\/json" id="cabang-data">(.*?)<\/script>/s', $response->getContent(), $m);
        $data = json_decode($m[1] ?? '[]', true);

        $this->assertCount(1, $data);
        $this->assertSame('SBY', $data[0]['kode']);
        $this->assertEqualsWithDelta(-7.257472, $data[0]['lat'], 0.000001);
        $this->assertEqualsWithDelta(112.752090, $data[0]['lng'], 0.000001);

        // The finder is a button, and the position is asked for on click only.
        $response->assertSee('id="cabang-cari"', escape: false);
        $this->assertStringContainsString("addEventListener('click'", $response->getContent());
    }

    public function test_the_public_site_declares_geolocation_for_itself_and_nothing_else(): void
    {
        $header = (string) $this->get('/kontak')->assertOk()->headers->get('Permissions-Policy');

        $this->assertStringContainsString('geolocation=(self)', $header);
        $this->assertStringContainsString('camera=()', $header);
    }

    public function test_the_owner_can_pin_a_branch_to_the_map(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value, 'is_active' => true]);
        $region = Region::factory()->create(['kode' => 'MLG', 'nama' => 'Cabang Malang']);

        Livewire::actingAs($owner)
            ->test(EditRegion::class, ['record' => $region->getKey()])
            ->fillForm(['lintang' => -7.966620, 'bujur' => 112.632632])
            ->call('save')
            ->assertHasNoFormErrors();

        $region->refresh();
        $this->assertTrue($region->punyaKoordinat());
        $this->assertEqualsWithDelta(-7.966620, $region->lintang, 0.000001);
        $this->assertEqualsWithDelta(112.632632, $region->bujur, 0.000001);
    }

    public function test_a_latitude_off_the_planet_is_refused(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value, 'is_active' => true]);
        $region = Region::factory()->create(['kode' => 'XYZ']);

        Livewire::actingAs($owner)
            ->test(EditRegion::class, ['record' => $region->getKey()])
            ->fillForm(['lintang' => 95, 'bujur' => 112])
            ->call('save')
            ->assertHasFormErrors(['lintang']);
    }
}
