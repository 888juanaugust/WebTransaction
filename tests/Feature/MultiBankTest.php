<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\BalanceSheet;
use App\Domain\Accounting\Ledger;
use App\Domain\Banking\BankAccounts;
use App\Domain\Banking\BankReconciler;
use App\Domain\Banking\StatementImporter;
use App\Domain\Banking\StatementMatcher;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierLedger;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentEntry;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * More than one rekening, one ledger.
 *
 * The design under test: each rekening is its own GL account minted beside
 * the original Bank (1-1100), the entry stores which rekening the money
 * touched **at the moment it is recorded**, and everything downstream — the
 * posting, the reversal, the reconciliation — reads that stored fact rather
 * than whatever the default happens to be later.
 *
 * The failure these tests exist to prevent is silent re-homing: the Owner
 * moves the default, and history quietly follows it. Money that arrived in
 * BCA must stay in BCA on the books forever, or the first reconciliation
 * after the change finds a hole exactly the size of everything before it.
 */
class MultiBankTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 09:00:00');

        $this->owner = User::factory()->role(Role::Owner)->create();
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create([
            'nama' => 'CV Dua Rekening',
            'status' => Company::STATUS_ACTIVE,
        ]);
    }

    // ------------------------------------------------------------- register

    public function test_the_migration_left_one_default_rekening_on_the_original_bank_account(): void
    {
        $bawaan = app(BankAccounts::class)->default();

        $this->assertTrue($bawaan->is_default);
        $this->assertSame(AccountCode::BANK, $bawaan->account->kode);
    }

    public function test_opening_a_rekening_mints_the_next_gl_code_beside_bank(): void
    {
        $rekening = $this->bukaRekening('BCA operasional');

        $this->assertSame('1-1101', $rekening->account->kode);
        $this->assertSame('Bank — BCA operasional', $rekening->account->nama);
        $this->assertFalse($rekening->is_default);

        // The minted account is a sibling of 1-1100, not a child or a stranger:
        // same type, same normal balance, same parent — so the trial balance
        // and neraca roll it up under ASET with no new plumbing.
        $bank = Account::byCode(AccountCode::BANK);
        $this->assertSame($bank->tipe, $rekening->account->tipe);
        $this->assertSame($bank->saldo_normal, $rekening->account->saldo_normal);
        $this->assertSame($bank->parent_id, $rekening->account->parent_id);

        $kedua = $this->bukaRekening('Mandiri gaji');
        $this->assertSame('1-1102', $kedua->account->kode);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'bank_account_opened')
            ->where('subject_id', (string) $rekening->id)
            ->count());
    }

    public function test_only_the_owner_opens_a_rekening(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Pemilik/');

        app(BankAccounts::class)->open('BCA gelap', 'BCA', '99', '', $this->finance);
    }

    // -------------------------------------------------------------- posting

    public function test_a_payment_to_the_chosen_rekening_posts_to_its_own_account(): void
    {
        $bca = $this->bukaRekening('BCA operasional');

        $this->terima(10_000_000, '2026-08-05');
        $this->terima(4_000_000, '2026-08-06', $bca);

        $ledger = app(Ledger::class);
        $this->assertSame(10_000_000, $ledger->balanceOf(AccountCode::BANK));
        $this->assertSame(4_000_000, $ledger->balanceOf('1-1101'));

        // And the neraca shows them as two separate asset lines.
        $neraca = BalanceSheet::asOf(Carbon::parse('2026-08-31'));
        $this->assertSame(10_000_000, $neraca->balanceOf(AccountCode::BANK));
        $this->assertSame(4_000_000, $neraca->balanceOf('1-1101'));
    }

    public function test_a_supplier_payment_leaves_from_the_chosen_rekening(): void
    {
        $bca = $this->bukaRekening('BCA operasional');
        $this->terima(10_000_000, '2026-08-01', $bca);

        $pemasok = Supplier::factory()->create();
        $bill = SupplierBill::factory()->create(['supplier_id' => $pemasok->id]);

        app(SupplierLedger::class)->recordPayment(
            supplier: $pemasok,
            amountRupiah: 3_000_000,
            actor: $this->finance,
            bill: $bill,
            paidAt: Carbon::parse('2026-08-10'),
            rekening: $bca,
        );

        $ledger = app(Ledger::class);
        $this->assertSame(7_000_000, $ledger->balanceOf('1-1101'));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::BANK));
    }

    public function test_a_reversal_follows_the_entry_not_the_default(): void
    {
        $bca = $this->bukaRekening('BCA operasional');
        $entry = $this->terima(4_000_000, '2026-08-06', $bca);

        // The default is still the original rekening — precisely the trap.
        // The reversal must undo the money where it actually landed, on BCA,
        // not where a payment recorded today would land.
        $pembalik = app(PaymentLedger::class)->reverse($entry, $this->finance, 'Salah rekam');

        $this->assertSame($bca->id, $pembalik->bank_account_id);
        $this->assertSame(0, app(Ledger::class)->balanceOf('1-1101'));
        $this->assertSame(0, app(Ledger::class)->balanceOf(AccountCode::BANK));
    }

    public function test_moving_the_default_never_rewrites_where_money_already_went(): void
    {
        $bca = $this->bukaRekening('BCA operasional');
        $lama = $this->terima(10_000_000, '2026-08-05');

        app(BankAccounts::class)->setDefault($bca, $this->owner);

        // History still says the old entry landed on the original rekening…
        $this->assertNotSame($bca->id, $lama->fresh()->bank_account_id);
        $this->assertSame(10_000_000, app(Ledger::class)->balanceOf(AccountCode::BANK));

        // …and only money recorded from now on lands on the new default.
        $this->terima(2_000_000, '2026-08-07');
        $this->assertSame(2_000_000, app(Ledger::class)->balanceOf('1-1101'));

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'bank_account_default_changed')->count());
    }

    public function test_a_legacy_entry_with_no_rekening_still_posts_to_the_original_bank(): void
    {
        // Every payment recorded before multi-bank existed has a null
        // bank_account_id. The poster must read that as 1-1100 forever.
        $entry = $this->terima(5_000_000, '2026-08-05');
        $entry->forceFill(['bank_account_id' => null])->save();

        $pembalik = app(PaymentLedger::class)->reverse($entry->fresh(), $this->finance, 'Uji warisan');

        $this->assertNull($pembalik->bank_account_id);
        $this->assertSame(0, app(Ledger::class)->balanceOf(AccountCode::BANK));
    }

    // ------------------------------------------------------- reconciliation

    public function test_each_rekening_reconciles_against_its_own_lines_only(): void
    {
        $bca = $this->bukaRekening('BCA operasional');

        $utama = $this->terima(10_000_000, '2026-08-05');
        $masukBca = $this->terima(4_000_000, '2026-08-06', $bca);

        $reconciler = app(BankReconciler::class);

        $recUtama = $reconciler->open(Carbon::parse('2026-08-31'), 10_000_000, $this->finance);
        $recBca = $reconciler->open(
            Carbon::parse('2026-08-31'), 4_000_000, $this->finance, rekening: $bca,
        );

        $this->assertSame(
            [$this->bankLegOf($utama)->id],
            $reconciler->candidateLines($recUtama)->pluck('id')->all(),
        );
        $this->assertSame(
            [$this->bankLegOf($masukBca)->id],
            $reconciler->candidateLines($recBca)->pluck('id')->all(),
        );

        // Ticking the other rekening's line is refused outright.
        try {
            $reconciler->tick($recUtama, $this->bankLegOf($masukBca), $this->finance);
            $this->fail('Baris rekening lain seharusnya ditolak.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('bukan milik rekening', $e->getMessage());
        }

        // And each desk signs off cleanly against its own statement.
        $reconciler->tickAll($recUtama, $this->finance);
        $reconciler->tickAll($recBca, $this->finance);
        $this->assertSame(0, $reconciler->summarise($recUtama)->selisih);
        $this->assertSame(0, $reconciler->summarise($recBca)->selisih);
    }

    public function test_a_statement_payment_lands_in_the_rekening_being_reconciled(): void
    {
        Storage::fake('local');

        $bca = $this->bukaRekening('BCA operasional');

        $rec = app(BankReconciler::class)->open(
            Carbon::parse('2026-08-31'), 0, $this->finance, rekening: $bca,
        );

        Storage::disk('local')->put(
            'mutasi-bank/bca.csv',
            "Tanggal;Uraian;Debit;Kredit\n05/08/2026;TRSF CV DUA REKENING;;2.500.000",
        );

        $import = app(StatementImporter::class)
            ->import($rec, 'mutasi-bank/bca.csv', 'bca.csv', $this->finance);

        $line = BankStatementLine::query()
            ->where('bank_statement_import_id', $import->id)->firstOrFail();

        $entry = app(StatementMatcher::class)->recordPayment(
            $line, $this->finance, company: $this->pelanggan,
        );

        $this->assertSame($bca->id, $entry->bank_account_id);
        $this->assertSame(2_500_000, app(Ledger::class)->balanceOf('1-1101'));
        $this->assertSame(0, app(Ledger::class)->balanceOf(AccountCode::BANK));
        $this->assertSame(BankStatementLine::STATUS_TERCOCOK, $line->fresh()->status);
    }

    // --------------------------------------------------------------- helpers

    private function bukaRekening(string $nama): BankAccount
    {
        return app(BankAccounts::class)->open($nama, 'BCA', '512-999-000', 'PT Kita', $this->owner);
    }

    private function terima(int $amount, string $date, ?BankAccount $rekening = null): PaymentEntry
    {
        return app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: $amount,
            actor: $this->finance,
            catatan: 'Transfer masuk',
            paidAt: Carbon::parse($date),
            rekening: $rekening,
        );
    }

    private function bankLegOf(PaymentEntry $entry): JournalLine
    {
        $journal = JournalEntry::query()
            ->where('source_type', PaymentEntry::class)
            ->where('source_id', (string) $entry->id)
            ->firstOrFail();

        $bankIds = Account::query()->where('kode', 'like', '1-11%')->pluck('id');

        return $journal->lines()->whereIn('account_id', $bankIds)->firstOrFail();
    }
}
