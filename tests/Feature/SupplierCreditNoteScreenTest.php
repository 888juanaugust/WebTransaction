<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Purchasing\SupplierCreditNoteIssuer;
use App\Filament\Resources\SupplierCreditNotes\Pages\ListSupplierCreditNotes;
use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierCreditNoteScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Supplier $pemasok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(SupplierCreditNoteResource::getUrl());

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

    public function test_drafting_one_from_the_screen_changes_nothing_yet(): void
    {
        Livewire::actingAs($this->finance)
            ->test(ListSupplierCreditNotes::class)
            ->callAction('catat', [
                'supplier_id' => $this->pemasok->id,
                'tanggal' => now()->toDateString(),
                'dasar_rupiah' => 500_000,
                'ppn_rupiah' => 0,
                'account' => AccountCode::SELISIH_HARGA_PEMBELIAN,
                'alasan' => 'Harga dikoreksi sesuai kesepakatan',
            ])
            ->assertHasNoActionErrors();

        $note = SupplierCreditNote::query()->sole();

        $this->assertTrue($note->isDraft());
        $this->assertSame(500_000, (int) $note->total_rupiah);
    }

    public function test_the_form_offers_no_account_the_domain_would_refuse(): void
    {
        /*
         * Offering a choice that is then rejected is a screen that lied. The
         * issuer refuses Persediaan and Utang Usaha, so neither may appear.
         */
        $offered = array_keys(ListSupplierCreditNotes::creditableAccounts());

        $this->assertContains(AccountCode::SELISIH_HARGA_PEMBELIAN, $offered);
        $this->assertContains(AccountCode::BIAYA_BELUM_DIALOKASIKAN, $offered);

        $this->assertNotContains(AccountCode::PERSEDIAAN, $offered);
        $this->assertNotContains(AccountCode::UTANG_USAHA, $offered);
        $this->assertNotContains('2-0000', $offered);

        // A supplier crediting us is not us selling anything.
        $this->assertNotContains(AccountCode::PENJUALAN, $offered);
    }

    public function test_posting_and_discarding_are_offered_only_on_a_draft(): void
    {
        $note = app(SupplierCreditNoteIssuer::class)->draft(
            supplier: $this->pemasok,
            tanggal: Carbon::now(),
            accountCode: AccountCode::SELISIH_HARGA_PEMBELIAN,
            dasarRupiah: 500_000,
            alasan: 'Koreksi harga',
            actor: $this->finance,
        );

        Livewire::actingAs($this->finance)
            ->test(ListSupplierCreditNotes::class)
            ->assertTableActionVisible('posting', $note)
            ->assertTableActionVisible('buang', $note);
    }

    public function test_a_note_can_never_be_edited_or_deleted(): void
    {
        $note = app(SupplierCreditNoteIssuer::class)->draft(
            supplier: $this->pemasok,
            tanggal: Carbon::now(),
            accountCode: AccountCode::SELISIH_HARGA_PEMBELIAN,
            dasarRupiah: 500_000,
            alasan: 'Koreksi harga',
            actor: $this->finance,
        );

        $this->assertFalse(SupplierCreditNoteResource::canEdit($note));
        $this->assertFalse(SupplierCreditNoteResource::canDelete($note));
        $this->assertFalse(SupplierCreditNoteResource::canCreate());
    }

    public function test_the_badge_counts_drafts_nobody_has_posted(): void
    {
        // A draft is a payable still overstated: the supplier has agreed to the
        // credit and the books have not heard about it.
        $this->actingAs($this->finance);

        $this->assertNull(SupplierCreditNoteResource::getNavigationBadge());

        app(SupplierCreditNoteIssuer::class)->draft(
            supplier: $this->pemasok,
            tanggal: Carbon::now(),
            accountCode: AccountCode::SELISIH_HARGA_PEMBELIAN,
            dasarRupiah: 500_000,
            alasan: 'Koreksi harga',
            actor: $this->finance,
        );

        $this->assertSame('1', SupplierCreditNoteResource::getNavigationBadge());
    }

    public function test_a_refusal_is_reported_rather_than_thrown(): void
    {
        // Persediaan is refused by the issuer; the action must survive it as a
        // notification rather than a 500.
        Livewire::actingAs($this->finance)
            ->test(ListSupplierCreditNotes::class)
            ->callAction('catat', [
                'supplier_id' => $this->pemasok->id,
                'tanggal' => now()->toDateString(),
                'dasar_rupiah' => 500_000,
                'ppn_rupiah' => 0,
                'account' => AccountCode::PERSEDIAAN,
                'alasan' => 'Percobaan',
            ]);

        $this->assertSame(0, SupplierCreditNote::query()->count());
    }
}
