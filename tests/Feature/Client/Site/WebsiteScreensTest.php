<?php

namespace Tests\Feature\Client\Site;

use App\Client\Access\CentralGroups;
use App\Client\Filament\Pages\Website;
use App\Client\Filament\Resources\SiteImages\Pages\ManageSiteImages;
use App\Client\Models\SiteImage;
use App\Client\Site\Copy;
use App\Models\Company\AuditLog;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The Owner's screens for the site: Website saves overrides, Website Images uploads a promo; neither opens for Sales. */
class WebsiteScreensTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
    }

    public function test_the_website_screen_saves_the_owners_copy_and_the_site_shows_it(): void
    {
        Livewire::test(Website::class)->assertOk()
            ->assertFormSet(['contact.phone' => config('site.contact.phone'), 'tagline.id' => 'Distributor grosir suku cadang otomotif'])
            ->fillForm([
                'contact.phone' => '+62 21 999 0000',
                'profile.en' => "First paragraph.\n\nSecond paragraph.",
                'partners' => [['name' => 'PT Mitra Satu', 'country' => 'Indonesia', 'since' => '2024', 'field' => ['id' => 'Pemasok', 'en' => 'Supplier'], 'description' => ['id' => 'Mitra utama.', 'en' => 'Main partner.']]],
            ])
            ->call('save')->assertHasNoFormErrors()->assertNotified();

        $this->assertSame('+62 21 999 0000', Copy::value('contact.phone'));
        $this->assertSame(['First paragraph.', 'Second paragraph.'], Copy::value('profile')['en']);
        $this->assertDatabaseMissing('site_settings', ['key' => 'contact.email']); // an untouched value is not stored
        $this->assertContains('site_setting_changed', AuditLog::query()->pluck('action')->all());

        auth()->forgetUser();
        $this->get('/kontak')->assertOk()->assertSee('+62 21 999 0000');
        $this->get('/mitra')->assertOk()->assertSee('PT Mitra Satu')->assertDontSee('Partner Name One');
        $this->withCookie('bahasa', 'en')->get('/mitra')->assertOk()->assertSee('Main partner.')->assertSee('Supplier');
    }

    public function test_website_images_upload_a_promo_and_a_photo_that_the_home_shows_while_live(): void
    {
        Storage::fake('public');

        Livewire::test(ManageSiteImages::class)->assertOk()->assertActionExists('create');
        Storage::disk('public')->put('promo/promo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $promo = SiteImage::query()->create(['kind' => SiteImage::PROMO, 'title' => ['id' => 'Promo Oktober', 'en' => 'October promo'], 'text' => ['id' => 'Diskon dus.', 'en' => 'Carton deal.'], 'image_path' => 'promo/promo.png', 'link' => '/kontak', 'is_active' => true]);
        $this->assertContains('created', AuditLog::query()->where('document_type', 'site_image')->pluck('action')->all());

        SiteImage::query()->create(['kind' => SiteImage::PHOTO, 'title' => ['id' => 'Gudang Jakarta', 'en' => 'Jakarta warehouse'], 'image_path' => 'promo/gudang.jpg', 'is_active' => true]);
        SiteImage::query()->create(['kind' => SiteImage::PROMO, 'title' => ['id' => 'Jahat', 'en' => 'Evil'], 'image_path' => 'promo/evil.jpg', 'link' => 'javascript:alert(1)', 'is_active' => true]);
        Livewire::test(ManageSiteImages::class)
            ->callAction('create', ['kind' => SiteImage::PROMO, 'title' => ['id' => 'x', 'en' => 'x'], 'link' => 'javascript:alert(1)', 'is_active' => true])
            ->assertHasActionErrors(['link']);
        $this->assertTrue(SiteImage::isSafeLink('/kontak') && SiteImage::isSafeLink('https://example.com/x'));
        $this->assertFalse(SiteImage::isSafeLink('//evil.example') || SiteImage::isSafeLink('javascript:alert(1)') || SiteImage::isSafeLink('data:text/html,x'));
        SiteImage::query()->create(['kind' => SiteImage::PHOTO, 'title' => ['id' => 'Lama', 'en' => 'Old'], 'image_path' => 'promo/old.jpg', 'is_active' => true, 'show_until' => today()->subDay()->toDateString()]);
        SiteImage::query()->create(['kind' => SiteImage::PROMO, 'title' => ['id' => 'Nanti', 'en' => 'Later'], 'image_path' => 'promo/later.jpg', 'is_active' => true, 'show_from' => today()->addDay()->toDateString()]);

        auth()->forgetUser();
        $this->get('/')->assertOk()
            ->assertSee('Promo Oktober')->assertSee('Diskon dus.')->assertSee('/storage/'.$promo->image_path, false)
            ->assertSee('Gudang Jakarta')->assertDontSee('Lama')->assertDontSee('Nanti')
            ->assertSee('Jahat')->assertDontSee('javascript:', false);
        $this->withCookie('bahasa', 'en')->get('/')->assertOk()->assertSee('October promo')->assertSee('Jakarta warehouse');
        $this->get('/sitemap.xml')->assertOk()->assertSee('<lastmod>'.today()->toDateString().'</lastmod>', false);
    }

    public function test_sales_opens_neither_screen(): void
    {
        $this->actingAs($this->sales);
        $this->freshRequest();
        $this->get(Website::getUrl())->assertForbidden();
        $this->get(ManageSiteImages::getUrl())->assertForbidden();
        $this->assertTrue(true, CentralGroups::SALES);
    }
}
