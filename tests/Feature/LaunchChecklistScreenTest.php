<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Launch\AttestationRecorder;
use App\Filament\Pages\KesiapanPeluncuran;
use App\Models\LaunchAttestation;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaunchChecklistScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->role(Role::Owner)->create();
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(KesiapanPeluncuran::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            // Deciding the business is cleared to trade online is not a
            // clerical act, and it is the person who answers for it if wrong.
            'pemilik' => [Role::Owner, true],
            'keuangan' => [Role::Finance, false],
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_the_badge_counts_what_is_still_outstanding(): void
    {
        $this->actingAs($this->owner);

        $before = (int) KesiapanPeluncuran::getNavigationBadge();
        $this->assertGreaterThan(0, $before);

        app(AttestationRecorder::class)->attest('pse', $this->owner, 'PB-UMKU 91234567890');

        $this->assertSame($before - 1, (int) KesiapanPeluncuran::getNavigationBadge());
    }

    public function test_the_badge_is_hidden_from_anybody_who_cannot_open_the_screen(): void
    {
        $this->actingAs(User::factory()->role(Role::Finance)->create());

        $this->assertNull(KesiapanPeluncuran::getNavigationBadge());
    }

    public function test_attesting_from_the_screen_records_the_evidence(): void
    {
        Livewire::actingAs($this->owner)
            ->test(KesiapanPeluncuran::class)
            ->callAction('nyatakan', ['catatan' => 'PB-UMKU 91234567890, terbit 14/07/2026'], [
                'kunci' => 'pse',
            ])
            ->assertHasNoActionErrors();

        $attestation = LaunchAttestation::query()->sole();

        $this->assertSame('pse', $attestation->kunci);
        $this->assertSame($this->owner->id, $attestation->attested_by);
        $this->assertStringContainsString('PB-UMKU', (string) $attestation->catatan);
    }

    public function test_the_evidence_is_required(): void
    {
        // A bare tick lets somebody clear six items in four seconds and leaves
        // nothing to ask about six months later.
        Livewire::actingAs($this->owner)
            ->test(KesiapanPeluncuran::class)
            ->callAction('nyatakan', ['catatan' => null], ['kunci' => 'pse'])
            ->assertHasActionErrors(['catatan']);

        $this->assertSame(0, LaunchAttestation::query()->count());
    }

    public function test_retracting_from_the_screen_puts_the_item_back(): void
    {
        app(AttestationRecorder::class)->attest('pse', $this->owner, 'PB-UMKU 91234567890');

        Livewire::actingAs($this->owner)
            ->test(KesiapanPeluncuran::class)
            ->callAction('cabut', ['alasan' => 'Ternyata belum terbit'], ['kunci' => 'pse'])
            ->assertHasNoActionErrors();

        $this->assertSame(0, LaunchAttestation::query()->count());
    }

    public function test_the_screen_refuses_to_attest_something_it_checks_itself(): void
    {
        /*
         * The rule the whole screen rests on. Reached here by passing the key
         * of a checked item straight to the action, which is what a crafted
         * request would do — the rendered page offers no such button.
         */
        Livewire::actingAs($this->owner)
            ->test(KesiapanPeluncuran::class)
            ->callAction('nyatakan', ['catatan' => 'sudah kok'], ['kunci' => 'identitas_pajak']);

        $this->assertSame(0, LaunchAttestation::query()->count());
        Notification::assertNotified('Tidak bisa dinyatakan');
    }

    public function test_the_page_renders_both_kinds_of_item(): void
    {
        Livewire::actingAs($this->owner)
            ->test(KesiapanPeluncuran::class)
            ->assertOk()
            ->assertSee('Diperiksa sistem')
            ->assertSee('Dinyatakan orang')
            ->assertSee('Terdaftar PSE Lingkup Privat');
    }
}
