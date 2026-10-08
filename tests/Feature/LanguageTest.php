<?php

namespace Tests\Feature;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use Livewire\Livewire;
use Tests\TestCase;

/** The screens in the company's language, or the user's own. */
class LanguageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAsAdmin();
    }

    public function test_the_company_default_and_a_users_own_language(): void
    {
        $this->get(CustomerResource::getUrl())->assertOk()->assertSee('Customers');

        app(Preferensi::class)->set(PreferensiKey::Language, 'id');
        $this->freshRequest();
        $this->get(CustomerResource::getUrl())->assertOk()->assertSee('Pelanggan')->assertSee('lang="id"', false);

        auth()->user()->forceFill(['locale' => 'en'])->save();
        $this->freshRequest();
        $this->get(CustomerResource::getUrl())->assertOk()->assertSee('Customers');
    }

    public function test_a_user_picks_their_language_on_the_profile(): void
    {
        Livewire::test(EditProfile::class)
            ->fillForm(['locale' => 'id'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('id', auth()->user()->fresh()->locale);
        $this->get(EditProfile::getUrl())->assertOk()->assertSee('lang="id"', false)->assertSee('Profil')
            ->assertSeeLivewire(EditProfile::class)->assertSee('Bahasa', false);
    }
}
