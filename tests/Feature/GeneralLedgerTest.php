<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\NormalBalance;
use App\Domain\Accounting\TrialBalance;
use App\Domain\Accounting\TrialBalanceRow;
use App\Domain\Accounting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The double-entry core.
 *
 * Every subledger in this system was already append-only and already correct
 * about its own thing. None of them could produce a balance sheet, because
 * nothing stated that the money leaving inventory is the money arriving in
 * cost of sales. These tests are about the property that makes that statement
 * checkable: debits equal credits, always, with no way in through the front
 * door to make them not.
 */
class GeneralLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->ledger = app(Ledger::class);
    }

    // ---------------------------------------------------------------- chart

    public function test_the_chart_seeds_every_account_the_posting_rules_name(): void
    {
        $reflection = new \ReflectionClass(AccountCode::class);

        foreach ($reflection->getConstants() as $name => $kode) {
            $this->assertNotNull(
                Account::query()->where('kode', $kode)->first(),
                "AccountCode::{$name} ({$kode}) is not in the seeded chart."
            );
        }
    }

    public function test_headers_cannot_be_posted_to_and_leaves_can(): void
    {
        $this->assertFalse(Account::byCode('1-0000')->dapat_diposting);
        $this->assertTrue(Account::byCode(AccountCode::KAS)->dapat_diposting);
    }

    public function test_every_posting_account_hangs_off_a_header(): void
    {
        Account::query()->where('dapat_diposting', true)->get()
            ->each(function (Account $account) {
                $this->assertNotNull($account->parent_id, "{$account->kode} has no parent");
                $this->assertFalse($account->parent->dapat_diposting);
            });
    }

    #[DataProvider('normalBalances')]
    public function test_normal_balance_follows_the_account_type(AccountType $tipe, NormalBalance $expected): void
    {
        $this->assertSame($expected, $tipe->normalBalance());

        Account::query()->where('tipe', $tipe)->get()
            ->each(fn (Account $a) => $this->assertSame($expected, $a->saldo_normal, $a->kode));
    }

    public static function normalBalances(): array
    {
        return [
            'aset' => [AccountType::Aset, NormalBalance::Debit],
            'kewajiban' => [AccountType::Kewajiban, NormalBalance::Kredit],
            'modal' => [AccountType::Modal, NormalBalance::Kredit],
            'pendapatan' => [AccountType::Pendapatan, NormalBalance::Kredit],
            'beban' => [AccountType::Beban, NormalBalance::Debit],
        ];
    }

    public function test_reseeding_does_not_rename_an_account_an_accountant_renamed(): void
    {
        Account::byCode(AccountCode::BANK)->forceFill(['nama' => 'Bank BCA 1234'])->save();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame('Bank BCA 1234', Account::byCode(AccountCode::BANK)->nama);
        $this->assertSame(1, Account::query()->where('kode', AccountCode::BANK)->count());
    }

    // ---------------------------------------------------------------- balance

    public function test_a_balanced_entry_posts_with_its_lines(): void
    {
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));

        $this->assertSame(11_100_000, $entry->total_debit_rupiah);
        $this->assertSame(11_100_000, $entry->total_kredit_rupiah);
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(3, $entry->lines);
        $this->assertMatchesRegularExpression('/^JU-\d{6}-\d{4}$/', $entry->nomor);
    }

    public function test_an_unbalanced_entry_is_refused_and_writes_nothing(): void
    {
        $draft = JournalDraft::manual('Salah hitung')
            ->debit(AccountCode::PIUTANG_USAHA, 11_100_000)
            ->kredit(AccountCode::PENJUALAN, 10_000_000);

        try {
            $this->ledger->post($draft);
            $this->fail('An unbalanced entry was accepted.');
        } catch (UnbalancedJournalException $e) {
            $this->assertSame(1_100_000, $e->difference());
            $this->assertStringContainsString('1.100.000', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
    }

    public function test_the_difference_is_named_because_its_size_is_the_diagnosis(): void
    {
        // A gap exactly equal to the PPN says the tax line was forgotten; a gap
        // of one rupiah says rounding. The message has to carry the number.
        $draft = JournalDraft::manual('PPN lupa')
            ->debit(AccountCode::PIUTANG_USAHA, 11_100_000)
            ->kredit(AccountCode::PENJUALAN, 11_100_001);

        $this->expectExceptionMessage('selisih -1');

        $this->ledger->post($draft);
    }

    public function test_a_single_line_entry_is_refused_by_the_balance_check(): void
    {
        // There is no separate "needs two lines" rule, and there should not be:
        // one line with an amount on it cannot balance, so this is the same
        // refusal as any other unbalanced entry.
        $this->expectException(UnbalancedJournalException::class);

        $this->ledger->post(JournalDraft::manual('Sebelah')->debit(AccountCode::KAS, 500_000));
    }

    public function test_an_entry_of_only_zero_lines_is_empty_not_balanced(): void
    {
        // Zero lines are dropped, so this draft would balance trivially at nil.
        // An entry saying nothing is refused as empty rather than accepted.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('says nothing');

        $this->ledger->post(
            JournalDraft::manual('Kosong')
                ->debit(AccountCode::KAS, 0)
                ->kredit(AccountCode::PENJUALAN, 0)
        );
    }

    public function test_a_negative_amount_is_refused_at_the_draft_not_at_the_ledger(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be negative');

        JournalDraft::manual('Negatif')->debit(AccountCode::KAS, -5_000);
    }

    public function test_zero_lines_are_dropped_so_a_nil_ppn_needs_no_branch(): void
    {
        $draft = JournalDraft::manual('Tanpa PPN')
            ->debit(AccountCode::PIUTANG_USAHA, 10_000_000)
            ->kredit(AccountCode::PENJUALAN, 10_000_000)
            ->kredit(AccountCode::PPN_KELUARAN, 0);

        $entry = $this->ledger->post($draft);

        $this->assertCount(2, $entry->lines);
    }

    public function test_a_signed_amount_lands_on_the_side_its_sign_names(): void
    {
        $over = JournalDraft::manual('Tagihan lebih tinggi')
            ->debitSigned(AccountCode::SELISIH_HARGA_PEMBELIAN, 250_000)
            ->kredit(AccountCode::UTANG_USAHA, 250_000);

        $under = JournalDraft::manual('Tagihan lebih rendah')
            ->debit(AccountCode::UTANG_BELUM_DITAGIH, 250_000)
            ->debitSigned(AccountCode::SELISIH_HARGA_PEMBELIAN, -250_000)
            ->kredit(AccountCode::UTANG_USAHA, 0);

        $this->assertSame(250_000, $this->ledger->post($over)->lines[0]->debit_rupiah);

        $lines = $this->ledger->post($under)->lines;
        $this->assertSame(0, $lines[1]->debit_rupiah);
        $this->assertSame(250_000, $lines[1]->kredit_rupiah);
    }

    // ---------------------------------------------------------------- accounts

    public function test_posting_to_a_header_account_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('akun induk');

        $this->ledger->post(
            JournalDraft::manual('Ke induk')
                ->debit('1-0000', 1_000)
                ->kredit(AccountCode::KAS, 1_000)
        );
    }

    public function test_posting_to_a_retired_account_is_refused(): void
    {
        Account::byCode(AccountCode::KAS)->forceFill(['aktif' => false])->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->ledger->post(
            JournalDraft::manual('Ke akun mati')
                ->debit(AccountCode::KAS, 1_000)
                ->kredit(AccountCode::BANK, 1_000)
        );
    }

    public function test_posting_to_an_account_that_does_not_exist_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('9-9999');

        $this->ledger->post(
            JournalDraft::manual('Akun karangan')
                ->debit('9-9999', 1_000)
                ->kredit(AccountCode::KAS, 1_000)
        );
    }

    // ---------------------------------------------------------------- idempotency

    public function test_a_document_posting_twice_produces_one_entry(): void
    {
        $company = $this->company();

        $first = $this->ledger->post($this->sale(10_000_000, 1_100_000, $company));
        $second = $this->ledger->post($this->sale(10_000_000, 1_100_000, $company));

        $this->assertTrue($first->is($second));
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_a_second_posting_cannot_slip_past_the_check_into_the_database(): void
    {
        // The in-PHP check is a courtesy; the unique index is the guarantee.
        $company = $this->company();
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000, $company));

        $this->expectException(QueryException::class);

        JournalEntry::query()->create([
            'nomor' => 'JU-209901-0001',
            'tanggal' => now(),
            'keterangan' => 'Duplikat',
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'jenis' => $entry->jenis,
            'total_debit_rupiah' => 1,
            'total_kredit_rupiah' => 1,
            'posted_at' => now(),
        ]);
    }

    public function test_one_document_can_post_two_different_kinds_of_entry(): void
    {
        // An order posts revenue when it is invoiced and cost when it is
        // shipped. The source is the same row; the entries are not.
        $company = $this->company();

        $this->ledger->post(
            JournalDraft::for($company, JournalEntry::JENIS_PENJUALAN, 'Penjualan')
                ->debit(AccountCode::PIUTANG_USAHA, 10_000_000)
                ->kredit(AccountCode::PENJUALAN, 10_000_000)
        );

        $this->ledger->post(
            JournalDraft::for($company, JournalEntry::JENIS_HPP, 'HPP')
                ->debit(AccountCode::HARGA_POKOK_PENJUALAN, 7_000_000)
                ->kredit(AccountCode::PERSEDIAAN, 7_000_000)
        );

        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertCount(2, $this->ledger->entriesForDocument($company));
    }

    public function test_manual_entries_are_never_deduplicated_against_each_other(): void
    {
        $finance = $this->staff(Role::Finance);

        foreach (range(1, 3) as $i) {
            $this->ledger->postManual(
                JournalDraft::manual("Beban listrik bulan {$i}")
                    ->debit(AccountCode::BEBAN_OPERASIONAL, 500_000)
                    ->kredit(AccountCode::BANK, 500_000),
                $finance,
            );
        }

        $this->assertSame(3, JournalEntry::query()->count());
    }

    // ---------------------------------------------------------------- authority

    #[DataProvider('roles')]
    public function test_only_finance_and_the_owner_may_write_a_manual_journal(Role $role, bool $allowed): void
    {
        $draft = JournalDraft::manual('Penyesuaian')
            ->debit(AccountCode::BEBAN_OPERASIONAL, 100_000)
            ->kredit(AccountCode::BANK, 100_000);

        if (! $allowed) {
            $this->expectException(DomainException::class);
        }

        $entry = $this->ledger->postManual($draft, $this->staff($role));

        $this->assertSame($role, $entry->postedBy->role);
    }

    public static function roles(): array
    {
        return [
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
        ];
    }

    public function test_a_document_posting_needs_no_actor_because_the_action_was_already_authorised(): void
    {
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));

        $this->assertNull($entry->posted_by);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_a_manual_journal_is_audited_and_a_document_posting_is_not(): void
    {
        // The document's own service already logged the action that caused it.
        // Logging the consequence as well would double every entry in the log.
        $this->ledger->post($this->sale(10_000_000, 1_100_000));
        $this->assertSame(0, AuditLog::query()->where('action', 'journal_posted_manually')->count());

        $this->ledger->postManual(
            JournalDraft::manual('Setoran modal')
                ->debit(AccountCode::BANK, 50_000_000)
                ->kredit(AccountCode::MODAL_DISETOR, 50_000_000),
            $this->staff(Role::Owner),
        );

        $this->assertSame(1, AuditLog::query()->where('action', 'journal_posted_manually')->count());
    }

    // ---------------------------------------------------------------- reversal

    public function test_a_reversal_mirrors_every_line_and_leaves_the_original_untouched(): void
    {
        $finance = $this->staff(Role::Finance);
        $original = $this->ledger->post($this->sale(10_000_000, 1_100_000));

        $reversal = $this->ledger->reverse($original, $finance, 'Salah pelanggan');

        $this->assertSame(11_100_000, $reversal->total_kredit_rupiah);
        $this->assertCount(3, $reversal->lines);

        foreach ($original->lines as $i => $line) {
            $this->assertSame($line->debit_rupiah, $reversal->lines[$i]->kredit_rupiah);
            $this->assertSame($line->kredit_rupiah, $reversal->lines[$i]->debit_rupiah);
            $this->assertSame($line->account_id, $reversal->lines[$i]->account_id);
        }

        $original->refresh();
        $this->assertSame(11_100_000, $original->total_debit_rupiah);
        $this->assertSame($reversal->id, $original->reversed_by_entry_id);
        $this->assertTrue($original->isReversed());
    }

    public function test_a_reversal_nets_every_account_back_to_where_it_started(): void
    {
        $finance = $this->staff(Role::Finance);
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));

        $this->assertSame(11_100_000, $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA));

        $this->ledger->reverse($entry, $finance, 'Dibatalkan');

        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::PENJUALAN));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::PPN_KELUARAN));
    }

    public function test_both_entries_stay_on_the_report_because_a_withdrawn_figure_is_part_of_the_record(): void
    {
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));
        $this->ledger->reverse($entry, $this->staff(Role::Finance), 'Dibatalkan');

        $this->assertSame(2, JournalEntry::query()->count());

        $tb = TrialBalance::asOf();
        $this->assertSame(22_200_000, $tb->totalDebit());
        $this->assertSame(22_200_000, $tb->totalKredit());
        $this->assertSame(0, $tb->balanceOf(AccountCode::PENJUALAN));
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $finance = $this->staff(Role::Finance);
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));

        $this->ledger->reverse($entry, $finance, 'Salah');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sudah dibalik');

        $this->ledger->reverse($entry->fresh(), $finance, 'Salah lagi');
    }

    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $finance = $this->staff(Role::Finance);
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));
        $reversal = $this->ledger->reverse($entry, $finance, 'Salah');

        $this->expectException(LogicException::class);

        $this->ledger->reverse($reversal, $finance, 'Berubah pikiran');
    }

    public function test_reversal_is_navigable_from_both_ends(): void
    {
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));
        $reversal = $this->ledger->reverse($entry, $this->staff(Role::Finance), 'Salah');

        $this->assertTrue($entry->fresh()->reversedBy->is($reversal));
        $this->assertTrue($reversal->reverses->is($entry));
        $this->assertTrue($reversal->isReversal());
        $this->assertFalse($entry->fresh()->isReversal());
    }

    #[DataProvider('roles')]
    public function test_only_finance_and_the_owner_may_reverse(Role $role, bool $allowed): void
    {
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));

        if (! $allowed) {
            $this->expectException(DomainException::class);
        }

        $reversal = $this->ledger->reverse($entry, $this->staff($role), 'Alasan');

        $this->assertStringContainsString('Alasan', $reversal->keterangan);
    }

    public function test_the_reason_for_a_reversal_survives_on_the_entry_and_in_the_audit_log(): void
    {
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000));

        $this->ledger->reverse($entry, $this->staff(Role::Finance), 'Barang diretur seluruhnya');

        $log = AuditLog::query()->where('action', 'journal_reversed')->sole();

        $this->assertSame('Barang diretur seluruhnya', $log->alasan);
        $this->assertSame($entry->nomor, $log->old_value['nomor']);
    }

    public function test_a_reversal_can_be_dated_into_the_period_it_belongs_to(): void
    {
        $entry = $this->ledger->post($this->sale(10_000_000, 1_100_000, tanggal: '2026-07-31'));

        $reversal = $this->ledger->reverse(
            $entry, $this->staff(Role::Finance), 'Ditemukan bulan berikutnya', new \DateTime('2026-08-05')
        );

        $this->assertSame('2026-07-31', $entry->tanggal->toDateString());
        $this->assertSame('2026-08-05', $reversal->tanggal->toDateString());

        // July still shows the sale; August nets it away.
        $this->assertSame(11_100_000, $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA, new \DateTime('2026-07-31')));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA, new \DateTime('2026-08-31')));
    }

    // ---------------------------------------------------------------- balances

    public function test_a_balance_is_read_in_the_accounts_own_direction(): void
    {
        $this->ledger->post(
            JournalDraft::manual('Setoran modal')
                ->debit(AccountCode::BANK, 50_000_000)
                ->kredit(AccountCode::MODAL_DISETOR, 50_000_000)
        );

        // Both positive: an asset grows on the debit side, equity on the credit.
        $this->assertSame(50_000_000, $this->ledger->balanceOf(AccountCode::BANK));
        $this->assertSame(50_000_000, $this->ledger->balanceOf(AccountCode::MODAL_DISETOR));
    }

    public function test_a_balance_as_at_a_date_excludes_what_came_after(): void
    {
        $this->ledger->post($this->sale(10_000_000, 1_100_000, tanggal: '2026-07-15'));
        $this->ledger->post($this->sale(20_000_000, 2_200_000, tanggal: '2026-08-15', jenis: JournalEntry::JENIS_MANUAL));

        $this->assertSame(11_100_000, $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA, new \DateTime('2026-07-31')));
        $this->assertSame(33_300_000, $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA));
    }

    public function test_a_date_boundary_includes_the_day_itself(): void
    {
        $this->ledger->post($this->sale(10_000_000, 1_100_000, tanggal: '2026-07-31'));

        $this->assertSame(11_100_000, $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA, new \DateTime('2026-07-31')));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA, new \DateTime('2026-07-30')));
    }

    // ---------------------------------------------------------------- trial balance

    public function test_the_trial_balance_totals_agree_whatever_is_posted(): void
    {
        $finance = $this->staff(Role::Finance);

        $this->ledger->post($this->sale(10_000_000, 1_100_000));
        $this->ledger->postManual(
            JournalDraft::manual('Beli persediaan')
                ->debit(AccountCode::PERSEDIAAN, 7_000_000)
                ->debit(AccountCode::PPN_MASUKAN, 770_000)
                ->kredit(AccountCode::UTANG_USAHA, 7_770_000),
            $finance,
        );

        $tb = TrialBalance::asOf();

        $this->assertTrue($tb->isBalanced());
        $this->assertSame(0, $tb->difference());
        $this->assertSame(18_870_000, $tb->totalDebit());
        $this->assertTrue($this->ledger->isBalanced());
    }

    public function test_the_trial_balance_as_at_a_date_excludes_what_came_after(): void
    {
        // The whole reason a trial balance takes a date: a report run in
        // September for August must not quietly include September's trading.
        $this->ledger->post($this->sale(10_000_000, 1_100_000, tanggal: '2026-07-15'));
        $this->ledger->post($this->sale(20_000_000, 2_200_000, tanggal: '2026-08-15', jenis: JournalEntry::JENIS_HPP));

        $juli = TrialBalance::asOf(new \DateTime('2026-07-31'));

        $this->assertSame(11_100_000, $juli->totalDebit());
        $this->assertSame(10_000_000, $juli->balanceOf(AccountCode::PENJUALAN));
        $this->assertTrue($juli->isBalanced());

        $this->assertSame(33_300_000, TrialBalance::asOf()->totalDebit());
    }

    public function test_the_trial_balance_boundary_includes_the_closing_day_itself(): void
    {
        $this->ledger->post($this->sale(10_000_000, 1_100_000, tanggal: '2026-07-31'));

        $this->assertSame(11_100_000, TrialBalance::asOf(new \DateTime('2026-07-31'))->totalDebit());
        $this->assertSame(0, TrialBalance::asOf(new \DateTime('2026-07-30'))->totalDebit());
    }

    public function test_every_postable_account_appears_even_with_nothing_in_it(): void
    {
        $expected = Account::query()->where('dapat_diposting', true)->count();

        $this->assertCount($expected, TrialBalance::asOf()->rows());
        $this->assertCount(0, TrialBalance::asOf()->rowsWithActivity());
    }

    public function test_headers_do_not_appear_because_nothing_can_post_to_them(): void
    {
        $codes = array_map(fn ($r) => $r->account->kode, TrialBalance::asOf()->rows());

        $this->assertNotContains('1-0000', $codes);
    }

    public function test_a_balance_is_printed_in_the_column_it_belongs_in(): void
    {
        $this->ledger->post($this->sale(10_000_000, 1_100_000));

        $tb = TrialBalance::asOf();
        $piutang = $this->row($tb, AccountCode::PIUTANG_USAHA);
        $penjualan = $this->row($tb, AccountCode::PENJUALAN);

        $this->assertSame(11_100_000, $piutang->debitBalance());
        $this->assertSame(0, $piutang->kreditBalance());
        $this->assertSame(10_000_000, $penjualan->kreditBalance());
        $this->assertSame(0, $penjualan->debitBalance());
    }

    public function test_a_balance_on_the_wrong_side_is_flagged_rather_than_hidden(): void
    {
        $this->ledger->post(
            JournalDraft::manual('Kelebihan bayar pelanggan')
                ->debit(AccountCode::BANK, 5_000_000)
                ->kredit(AccountCode::PIUTANG_USAHA, 5_000_000)
        );

        $piutang = $this->row(TrialBalance::asOf(), AccountCode::PIUTANG_USAHA);

        $this->assertSame(-5_000_000, $piutang->balance());
        $this->assertTrue($piutang->isContrary());
        $this->assertSame(5_000_000, $piutang->kreditBalance());
    }

    public function test_the_trial_balance_is_built_in_one_query_not_one_per_account(): void
    {
        $this->ledger->post($this->sale(10_000_000, 1_100_000));

        DB::enableQueryLog();
        TrialBalance::asOf();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, count($queries), 'The trial balance is scanning per account.');
    }

    public function test_totals_by_type_read_in_each_types_own_direction(): void
    {
        $this->ledger->post(
            JournalDraft::manual('Setoran modal')
                ->debit(AccountCode::BANK, 50_000_000)
                ->kredit(AccountCode::MODAL_DISETOR, 50_000_000)
        );

        $tb = TrialBalance::asOf();

        $this->assertSame(50_000_000, $tb->totalOfType(AccountType::Aset));
        $this->assertSame(50_000_000, $tb->totalOfType(AccountType::Modal));
        $this->assertSame(0, $tb->totalOfType(AccountType::Beban));
    }

    // ---------------------------------------------------------------- reconstruction

    public function test_the_cached_totals_on_an_entry_match_its_lines(): void
    {
        $this->ledger->post($this->sale(10_000_000, 1_100_000));
        $this->ledger->post($this->sale(3_000_000, 330_000, jenis: JournalEntry::JENIS_MANUAL));

        JournalEntry::query()->with('lines')->get()->each(function (JournalEntry $entry) {
            $this->assertSame($entry->lines->sum('debit_rupiah'), $entry->total_debit_rupiah, $entry->nomor);
            $this->assertSame($entry->lines->sum('kredit_rupiah'), $entry->total_kredit_rupiah, $entry->nomor);
        });
    }

    public function test_a_line_carries_who_it_is_about_so_a_control_account_can_be_proved(): void
    {
        $company = $this->company();

        $this->ledger->post(
            JournalDraft::for($company, JournalEntry::JENIS_PENJUALAN, 'Penjualan')
                ->debit(AccountCode::PIUTANG_USAHA, 11_100_000, company: $company)
                ->kredit(AccountCode::PENJUALAN, 10_000_000)
                ->kredit(AccountCode::PPN_KELUARAN, 1_100_000)
        );

        $perCompany = (int) JournalLine::query()
            ->where('account_id', Account::byCode(AccountCode::PIUTANG_USAHA)->id)
            ->where('company_id', $company->id)
            ->sum('debit_rupiah');

        $this->assertSame($this->ledger->balanceOf(AccountCode::PIUTANG_USAHA), $perCompany);
    }

    public function test_journal_numbers_follow_the_date_the_entry_belongs_to(): void
    {
        $this->ledger->post($this->sale(1_000_000, 110_000, tanggal: '2026-07-15'));
        $this->ledger->post($this->sale(1_000_000, 110_000, tanggal: '2026-07-20', jenis: JournalEntry::JENIS_MANUAL));
        $this->ledger->post($this->sale(1_000_000, 110_000, tanggal: '2026-08-02', jenis: JournalEntry::JENIS_HPP));

        $nomor = JournalEntry::query()->orderBy('id')->pluck('nomor')->all();

        $this->assertSame(['JU-202607-0001', 'JU-202607-0002', 'JU-202608-0001'], $nomor);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A sale, as a document posting. The Company stands in for whatever real
     * document would cause it — these tests are about the ledger, not about
     * which model is on the other end of the polymorphic key.
     */
    private function sale(
        int $hargaJual,
        int $ppn,
        ?Company $company = null,
        string $jenis = JournalEntry::JENIS_PENJUALAN,
        ?string $tanggal = null,
    ): JournalDraft {
        $company ??= $this->company();

        return JournalDraft::for($company, $jenis, 'Penjualan', $tanggal ? new \DateTime($tanggal) : null)
            ->debit(AccountCode::PIUTANG_USAHA, $hargaJual + $ppn, company: $company)
            ->kredit(AccountCode::PENJUALAN, $hargaJual)
            ->kredit(AccountCode::PPN_KELUARAN, $ppn);
    }

    private function row(TrialBalance $tb, string $kode): TrialBalanceRow
    {
        foreach ($tb->rows() as $row) {
            if ($row->account->kode === $kode) {
                return $row;
            }
        }

        $this->fail("Akun {$kode} tidak muncul di neraca saldo.");
    }

    private function company(): Company
    {
        return Company::factory()->create();
    }

    private function staff(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}
