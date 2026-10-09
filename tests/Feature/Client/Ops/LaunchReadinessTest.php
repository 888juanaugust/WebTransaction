<?php

namespace Tests\Feature\Client\Ops;

use App\Client\Domain\Ops\Launch\AttestationRecorder;
use App\Client\Domain\Ops\Launch\LaunchCheck;
use App\Client\Domain\Ops\Launch\LaunchReadiness as Readiness;
use App\Client\Filament\Pages\LaunchReadiness;
use App\Client\Models\BackupRun;
use App\Client\Models\PriceListVersion;
use App\Client\Site\SiteSettings;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Readiness: every automatic check fails on a fresh install for a reason it names, passes once fixed; attestations are a person's word, audited. */
class LaunchReadinessTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
    }

    private function fresh(): Readiness
    {
        app(Readiness::class)->forget();

        return app(Readiness::class);
    }

    /** @return array<string, LaunchCheck> */
    private function byKey(): array
    {
        $out = [];
        foreach ($this->fresh()->checks() as $check) {
            $out[$check->key] = $check;
        }

        return $out;
    }

    public function test_a_fresh_install_is_not_ready_and_every_open_item_says_what_to_do(): void
    {
        $checks = $this->byKey();
        $this->assertCount(17, $checks);
        foreach (['environment', 'mail', 'site_contact', 'partners', 'price_list', 'two_factor', 'backup', 'first_invoice', 'pse', 'restore_drilled'] as $key) {
            $this->assertFalse($checks[$key]->passed, $key);
        }
        $this->assertStringContainsString('APP_ENV=testing', $checks['environment']->finding);
        $this->assertStringContainsString('contact.phone', $checks['site_contact']->finding);
        $this->assertStringContainsString('example name', $checks['partners']->finding);
        $this->assertTrue($checks['integrity']->passed);
        $this->assertFalse($checks['staff_passwords']->passed, 'the fixtures sign in with "password"');
        $this->assertStringContainsString('"password"', $checks['staff_passwords']->finding);
        foreach ($checks as $check) {
            if ($check->automatic && ! $check->passed) {
                $this->assertNotEmpty($check->action, $check->key);
            }
        }
        $this->artisan('central:launch-check')->assertFailed()->expectsOutputToContain('OPEN  checked   Production environment');
    }

    public function test_each_automatic_check_passes_once_its_cause_is_fixed(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://central.example', 'mail.default' => 'smtp']);
        app(Preferensi::class)->setMany([PreferensiKey::CompanyName->value => 'PT Central', PreferensiKey::CompanyAddress->value => 'Jl. Nyata 1', PreferensiKey::CompanyNpwp->value => '01.234.567.8-901.000', PreferensiKey::AdministratorTwoFactor->value => true]);
        app(SiteSettings::class)->setMany(['contact.phone' => '+62 21 555 0100', 'contact.whatsapp' => '+62 812 555 0100', 'contact.email' => 'sales@central.example', 'partners' => [['name' => 'PT Mitra Nyata', 'country' => 'Indonesia', 'since' => '2024', 'field' => ['id' => 'Pemasok', 'en' => 'Supplier'], 'description' => ['id' => 'x', 'en' => 'x']]]]);
        BackupRun::query()->create(['started_at' => now(), 'status' => BackupRun::VERIFIED, 'disk' => 's3', 'offsite' => true]);
        User::query()->update(['password' => Hash::make('Str0ng-and-long!')]);
        $this->stock($this->gudangJakarta, 10);
        $this->invoice(1, 100_000);
        $this->publishVersionForReadiness();

        $checks = $this->byKey();
        foreach ($checks as $check) {
            if ($check->automatic) {
                $this->assertTrue($check->passed, $check->key.': '.$check->finding);
            }
        }
        $this->assertSame(6, $this->fresh()->outstanding(), 'the attested items remain');

        $weak = User::factory()->create(['access_type' => 'operator', 'is_active' => true, 'password' => 'password']);
        $this->assertFalse($this->byKey()['staff_passwords']->passed);
        $weak->forceFill(['is_active' => false])->save();
        $this->assertTrue($this->byKey()['staff_passwords']->passed, 'an inactive account does not count');
    }

    public function test_attestations_are_an_administrators_word_with_evidence_audited_and_withdrawable(): void
    {
        $recorder = app(AttestationRecorder::class);
        try {
            $recorder->attest('pse', $this->sales, 'PB-UMKU 1');
            $this->fail('sales attested');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('administrator', $e->getMessage());
        }
        try {
            $recorder->attest('backup', $this->owner, 'x');
            $this->fail('a checked item was attested');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Not an item', $e->getMessage());
        }
        try {
            $recorder->attest('pse', $this->owner, '  ');
            $this->fail('no evidence');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('evidence', $e->getMessage());
        }

        $recorder->attest('pse', $this->owner, 'PB-UMKU 91234567890123, 14/07/2026');
        $pse = $this->byKey()['pse'];
        $this->assertTrue($pse->passed);
        $this->assertSame($this->owner->name, $pse->attestedBy);
        $this->assertStringContainsString('PB-UMKU', $pse->note);
        $this->assertContains('launch_item_attested', AuditLog::query()->pluck('action')->all());

        $recorder->retract('pse', $this->owner, 'the number was wrong');
        $this->assertFalse($this->byKey()['pse']->passed);
        $this->assertContains('launch_item_retracted', AuditLog::query()->pluck('action')->all());
        $this->assertDatabaseMissing('launch_attestations', ['key' => 'pse']);
    }

    public function test_the_screen_lists_both_kinds_and_attests_from_a_modal_for_administrators_only(): void
    {
        Livewire::test(LaunchReadiness::class)->assertOk()
            ->assertSee('Checked by the system')->assertSee('Attested by a person')->assertSee('Production environment')->assertSee('PSE Lingkup Privat')
            ->callAction('attest', ['note' => 'PB-UMKU 1234'], ['key' => 'kbli'])->assertHasNoActionErrors()->assertNotified()
            ->assertSee('PB-UMKU 1234');
        $this->assertSame((string) $this->fresh()->outstanding(), LaunchReadiness::getNavigationBadge());

        Livewire::test(LaunchReadiness::class)->callAction('retract', ['reason' => 'typo'], ['key' => 'kbli'])->assertHasNoActionErrors();
        $this->assertDatabaseMissing('launch_attestations', ['key' => 'kbli']);

        $this->actingAs($this->sales);
        $this->freshRequest();
        $this->get(LaunchReadiness::getUrl())->assertForbidden();
    }

    private function publishVersionForReadiness(): void
    {
        PriceListVersion::query()->create(['effective_from' => today(), 'status' => 'published', 'published_at' => now(), 'published_by' => $this->owner->id]);
    }
}
