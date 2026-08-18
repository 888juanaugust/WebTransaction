<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Giro\GiroDirection;
use App\Domain\Giro\GiroRegister;
use App\Domain\Giro\GiroStatus;
use App\Filament\Resources\Giros\GiroResource;
use App\Filament\Resources\Giros\Pages\ListGiros;
use App\Filament\Resources\Giros\Pages\ViewGiro;
use App\Filament\Widgets\GiroDue;
use App\Models\Company;
use App\Models\Giro;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The giro register on screen, and who is shown it.
 *
 * Behind `canHandleGiro()`, which is Finance and Owner. Sales are excluded
 * even though they are usually the ones handed the paper at the counter:
 * recording a giro changes the books and recording a bounce changes them back,
 * which is moving what a customer owes by another name.
 */
class GiroScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Company $pelanggan;

    private Supplier $pemasok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);
        $this->pelanggan = Company::factory()->creditLimit(100_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'status' => Company::STATUS_ACTIVE,
        ]);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_register(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, GiroResource::canViewAny());
    }

    #[DataProvider('roles')]
    public function test_the_route_refuses_not_just_the_menu(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(GiroResource::getUrl('index'));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_a_giro_is_registered_through_the_header_action(): void
    {
        // No create page: a giro's number, value and date are facts printed by
        // somebody else, and the action asks for exactly those.
        $this->actingAs($this->finance);
        $this->assertFalse(GiroResource::canCreate());

        Livewire::actingAs($this->finance)
            ->test(ListGiros::class)
            ->callAction('terima', [
                'company_id' => $this->pelanggan->id,
                'bank_penerbit' => 'bca',
                'nomor_warkat' => 'AB123456',
                'nilai_rupiah' => 40_000_000,
                'tanggal_terima' => now()->toDateString(),
                'tanggal_jatuh_tempo' => now()->addDays(60)->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $giro = Giro::query()->sole();

        $this->assertSame(GiroDirection::Masuk, $giro->arah);
        $this->assertSame(GiroStatus::Beredar, $giro->status);
        $this->assertSame(40_000_000, (int) $giro->nilai_rupiah);
        // Folded on the way in, so the uniqueness check cannot be dodged.
        $this->assertSame('BCA', $giro->bank_penerbit);
    }

    public function test_a_giro_we_write_is_registered_through_its_own_action(): void
    {
        /*
         * Two actions rather than one with a direction picker. The fields
         * differ — customer and invoice against supplier and bill — and a
         * single form with half its fields hidden behind a radio is how the
         * wrong counterparty gets picked.
         */
        Livewire::actingAs($this->finance)
            ->test(ListGiros::class)
            ->callAction('terbitkan', [
                'supplier_id' => $this->pemasok->id,
                'bank_penerbit' => 'BCA',
                'nomor_warkat' => 'KL999999',
                'nilai_rupiah' => 12_000_000,
                'tanggal_terima' => now()->toDateString(),
                'tanggal_jatuh_tempo' => now()->addDays(30)->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $giro = Giro::query()->sole();

        $this->assertSame(GiroDirection::Keluar, $giro->arah);
        $this->assertSame($this->pemasok->id, $giro->supplier_id);
        $this->assertNull($giro->company_id);
    }

    public function test_a_duplicate_warkat_is_refused_with_a_sentence(): void
    {
        // The database enforces it too. This is so the person typing gets an
        // explanation rather than a constraint violation.
        $this->registerGiro('AB123456');

        Livewire::actingAs($this->finance)
            ->test(ListGiros::class)
            ->callAction('terima', [
                'company_id' => $this->pelanggan->id,
                'bank_penerbit' => 'BCA',
                'nomor_warkat' => 'AB123456',
                'nilai_rupiah' => 1_000_000,
                'tanggal_terima' => now()->toDateString(),
                'tanggal_jatuh_tempo' => now()->addDays(30)->toDateString(),
            ]);

        $this->assertSame(1, Giro::query()->count());
    }

    public function test_the_transitions_that_do_not_apply_are_not_offered(): void
    {
        /*
         * A row of buttons where three will refuse teaches people to click
         * hopefully, which is the opposite of what a screen that moves money
         * should teach.
         */
        $giro = $this->registerGiro('AB111111');

        Livewire::actingAs($this->finance)
            ->test(ViewGiro::class, ['record' => $giro->getKey()])
            ->assertActionVisible('cair')
            ->assertActionVisible('tolak')
            ->assertActionVisible('batal')
            // Not bankable until the date printed on it, so the button is not
            // there at all — offering one that would refuse is the same lie.
            ->assertActionHidden('setor');

        $due = $this->registerGiro('AB111112', dueOn: now()->subDay(), receivedOn: now()->subDays(60));

        Livewire::actingAs($this->finance)
            ->test(ViewGiro::class, ['record' => $due->getKey()])
            ->assertActionVisible('setor');

        app(GiroRegister::class)->clear($giro, $this->finance);

        Livewire::actingAs($this->finance)
            ->test(ViewGiro::class, ['record' => $giro->getKey()])
            ->assertActionHidden('cair')
            ->assertActionHidden('tolak')
            ->assertActionHidden('batal')
            ->assertActionHidden('setor');
    }

    public function test_bouncing_a_giro_from_the_screen_asks_what_the_bank_said(): void
    {
        $giro = $this->registerGiro('AB222222');

        Livewire::actingAs($this->finance)
            ->test(ViewGiro::class, ['record' => $giro->getKey()])
            ->callAction('tolak', [
                'alasan' => 'Saldo tidak cukup',
                'tanggal' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $giro->refresh();

        $this->assertSame(GiroStatus::Ditolak, $giro->status);
        $this->assertSame('Saldo tidak cukup', $giro->alasan_selesai);
    }

    public function test_the_detail_screen_says_in_words_that_nothing_has_been_paid(): void
    {
        /*
         * "This is not a payment" is the whole feature, and it is what somebody
         * holding a forty-million-rupiah cheque most wants reassuring about.
         * Leaving it to be inferred from two account names is not saying it.
         */
        $giro = $this->registerGiro('AB333333');

        $this->actingAs($this->finance, 'web')
            ->get(GiroResource::getUrl('view', ['record' => $giro]))
            ->assertOk()
            ->assertSee('Belum ada uang masuk', false)
            ->assertSee('plafon kredit pelanggan belum kembali', false);
    }

    public function test_the_detail_screen_changes_its_story_once_it_clears(): void
    {
        $giro = $this->registerGiro('AB444444');

        app(GiroRegister::class)->clear($giro, $this->finance);

        $this->actingAs($this->finance, 'web')
            ->get(GiroResource::getUrl('view', ['record' => $giro]))
            ->assertOk()
            ->assertSee('dicatat sebagai pembayaran masuk', false)
            ->assertDontSee('Belum ada uang masuk', false);
    }

    public function test_a_giro_ready_to_bank_shows_on_the_sidebar(): void
    {
        $this->actingAs($this->finance);

        $this->assertNull(GiroResource::getNavigationBadge());

        $giro = $this->registerGiro('AB555555', dueOn: now()->subDay(), receivedOn: now()->subDays(60));

        $this->assertSame('1', GiroResource::getNavigationBadge());

        // Once it is at the bank it is somebody else's turn, so it drops off.
        app(GiroRegister::class)->markDeposited($giro, $this->finance);

        $this->assertNull(GiroResource::getNavigationBadge());
    }

    public function test_the_dashboard_queue_stays_silent_when_nothing_is_due(): void
    {
        // A queue that is always on screen stops being read.
        $this->actingAs($this->finance);

        $this->registerGiro('AB666666', dueOn: now()->addDays(45));

        $this->assertFalse(GiroDue::canView());
    }

    public function test_the_dashboard_queue_appears_when_a_giro_comes_due(): void
    {
        $this->actingAs($this->finance);

        $this->registerGiro('AB777777', dueOn: now(), receivedOn: now()->subDays(30));

        $this->assertTrue(GiroDue::canView());
    }

    public function test_our_own_giro_stays_on_the_queue_until_it_clears(): void
    {
        /*
         * An incoming giro drops off once it is banked — the paper has left the
         * drawer. An outgoing one cannot, because until it clears the money
         * still has to be in the account on the day it is presented.
         */
        $this->actingAs($this->finance);

        app(GiroRegister::class)->issue(
            supplier: $this->pemasok,
            nilaiRupiah: 5_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'KL888888',
            jatuhTempo: now(),
            actor: $this->finance,
            diserahkan: now()->subDays(30),
        );

        $this->assertTrue(GiroDue::canView());
    }

    public function test_warehouse_never_sees_the_queue(): void
    {
        $this->actingAs(User::factory()->role(Role::Warehouse)->create());

        $this->registerGiro('AB999999', dueOn: now(), receivedOn: now()->subDays(30));

        $this->assertFalse(GiroDue::canView());
    }

    // --- helpers ------------------------------------------------------------

    private function registerGiro(
        string $warkat,
        ?\DateTimeInterface $dueOn = null,
        ?\DateTimeInterface $receivedOn = null,
    ): Giro {
        return app(GiroRegister::class)->receive(
            company: $this->pelanggan,
            nilaiRupiah: 40_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: $warkat,
            jatuhTempo: $dueOn ?? now()->addDays(60),
            actor: $this->finance,
            diterima: $receivedOn,
        );
    }
}
