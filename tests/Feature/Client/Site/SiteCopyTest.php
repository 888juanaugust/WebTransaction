<?php

namespace Tests\Feature\Client\Site;

use App\Client\Site\Copy;
use App\Client\Site\SiteSettings;
use App\Domain\Shared\Locales;
use App\Models\Company\AuditLog;
use Tests\TestCase;

/** The company's copy: every pair carries both sides, the language being spoken picks one, the Owner's setting wins over the config. */
class SiteCopyTest extends TestCase
{
    public function test_every_bilingual_pair_in_the_config_carries_both_sides(): void
    {
        $missing = [];
        $walk = function (mixed $value, string $path) use (&$walk, &$missing): void {
            if (! is_array($value)) {
                return;
            }
            if (Copy::isPair($value) || array_key_exists('id', $value) || array_key_exists('en', $value)) {
                foreach (Copy::LANGUAGES as $code) {
                    if (! array_key_exists($code, $value) || $value[$code] === '' || $value[$code] === []) {
                        $missing[] = "{$path}.{$code}";
                    }
                }
                if (is_array($value['id'] ?? null) && is_array($value['en'] ?? null) && count($value['id']) !== count($value['en'])) {
                    $missing[] = "{$path}: {$path}.id and {$path}.en differ in length";
                }

                return;
            }
            foreach ($value as $k => $v) {
                $walk($v, "{$path}.{$k}");
            }
        };
        $walk(config('site'), 'site');

        $this->assertSame([], $missing, 'a pair with a side missing');
    }

    public function test_the_copy_follows_the_language_being_spoken_and_falls_back_to_indonesian(): void
    {
        Locales::apply('id');
        $this->assertSame('Distributor grosir suku cadang otomotif', Copy::text('tagline'));
        $this->assertSame('Bengkel', Copy::records('serves')[0]['title']);
        $this->assertCount(3, Copy::list('profile'));

        Locales::apply('en');
        $this->assertSame('Wholesale distributor of automotive spare parts', Copy::text('tagline'));
        $this->assertSame('Workshops', Copy::records('serves')[0]['title']);
        $this->assertSame('YUHOLI', Copy::list('brands')[0], 'a plain list passes through');

        $this->assertSame('hanya Indonesia', Copy::pick(['id' => 'hanya Indonesia']), 'a lonely side still says something');
        $this->assertSame('plain', Copy::pick('plain'));
        Locales::apply('en');
    }

    public function test_a_setting_overrides_the_config_and_is_audited_and_forgotten_when_put_back(): void
    {
        $settings = app(SiteSettings::class);
        $this->actingAsAdmin();

        $settings->set('contact.phone', '+62 21 777 0000');
        $settings->set('legal', ['entity' => 'CV', 'nib' => '1234567890123']);
        $settings->set('tagline', ['id' => 'Grosir suku cadang', 'en' => 'Parts wholesale']);

        $this->assertSame('+62 21 777 0000', Copy::value('contact.phone'));
        $this->assertSame('1234567890123', Copy::value('legal.nib'), 'dug out of a stored parent');
        $this->assertNull(Copy::value('legal.established'), 'a parent stored without the key answers nothing, not the config');
        $this->assertSame(config('site.contact.email'), Copy::value('contact.email'), 'untouched keys keep the config');
        Locales::apply('en');
        $this->assertSame('Parts wholesale', Copy::text('tagline'));
        $this->get('/kontak')->assertOk()->assertSee('+62 21 777 0000');
        $this->get('/sitemap.xml')->assertOk()->assertSee('<lastmod>'.today()->toDateString().'</lastmod>', false);

        $logs = AuditLog::query()->where('action', 'site_setting_changed')->get();
        $this->assertCount(3, $logs);
        $this->assertSame(config('site.contact.phone'), $logs->firstWhere('reference', 'contact.phone')->meta['before']);
        $this->assertSame('+62 21 777 0000', $logs->firstWhere('reference', 'contact.phone')->meta['after']);

        $settings->set('contact.phone', config('site.contact.phone'));
        $this->assertDatabaseMissing('site_settings', ['key' => 'contact.phone']);
        $this->assertSame(4, AuditLog::query()->where('action', 'site_setting_changed')->count(), 'putting it back is a change too');
        $settings->set('contact.phone', config('site.contact.phone'));
        $this->assertSame(4, AuditLog::query()->where('action', 'site_setting_changed')->count(), 'nothing changed, nothing logged');
    }
}
