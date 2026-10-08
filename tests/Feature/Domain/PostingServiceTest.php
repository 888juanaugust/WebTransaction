<?php

namespace Tests\Feature\Domain;

use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\Exceptions\DocumentLockedException;
use App\Domain\Posting\Exceptions\PeriodClosedException;
use App\Domain\Posting\Exceptions\UnbalancedPostingException;
use App\Domain\Posting\PeriodLock;
use App\Domain\Posting\PostingService;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\AccountOpeningBalance;
use App\Models\GeneralLedger\DocumentRevision;
use App\Models\GeneralLedger\JournalLine;
use App\Models\GeneralLedger\JournalVoucher;
use App\Models\GeneralLedger\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\System\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostingServiceTest extends TestCase
{
    private Account $cash;

    private Account $sales;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->seed(ChartOfAccountsSeeder::class);
        $this->actingAsAdmin();
        $this->cash = Account::query()->where('no', '1101')->firstOrFail();
        $this->sales = Account::query()->where('no', '4100')->firstOrFail();
    }

    private function voucher(int $amount, string $date = '2026-10-17'): JournalVoucher
    {
        $voucher = JournalVoucher::query()->create(['number' => 'JV-'.uniqid(), 'trans_date' => $date, 'description' => 'cash sale']);
        $voucher->lines()->createMany([
            ['sort' => 0, 'account_id' => $this->cash->id, 'debit' => $amount, 'credit' => 0],
            ['sort' => 1, 'account_id' => $this->sales->id, 'debit' => 0, 'credit' => $amount],
        ]);
        $voucher->refreshTotal();

        return $voucher->fresh();
    }

    public function test_a_document_posts_balanced_journal_lines_and_reposts_by_superseding(): void
    {
        $service = app(PostingService::class);
        $voucher = $this->voucher(500_000);

        $first = $service->post($voucher);
        $this->assertSame(1, $first->revision);
        $this->assertSame(2, $first->journalLines()->count());
        $this->assertSame(500_000, AccountBalances::asOf()[$this->cash->id]);
        $this->assertSame(500_000, AccountBalances::asOf()[$this->sales->id], 'credit-normal accounts read positive');

        $voucher->lines()->update(['debit' => DB::raw('debit * 2'), 'credit' => DB::raw('credit * 2')]);
        $second = $service->post($voucher->fresh());

        $this->assertSame(2, $second->revision);
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertSame(1, Posting::active()->where('posting_key', $voucher->postingKey())->count());
        $this->assertSame(1_000_000, AccountBalances::asOf()[$this->cash->id], 'only the active posting counts');
        $this->assertSame(4, JournalLine::query()->count(), 'superseded lines stay as history');

        $service->unpost($voucher);
        $this->assertSame(0, AccountBalances::asOf()[$this->cash->id]);
    }

    public function test_an_unbalanced_document_is_refused_and_nothing_is_written(): void
    {
        $voucher = $this->voucher(100);
        $voucher->lines()->where('credit', '>', 0)->update(['credit' => 90]);

        try {
            app(PostingService::class)->post($voucher->fresh());
            $this->fail('expected an unbalanced posting to be refused');
        } catch (UnbalancedPostingException) {
        }
        $this->assertSame(0, Posting::query()->count());
    }

    public function test_the_ledgers_refuse_updates_deletes_and_rewriting_a_posting(): void
    {
        $posting = app(PostingService::class)->post($this->voucher(100));
        $line = $posting->journalLines()->first();

        foreach ([
            fn () => JournalLine::query()->whereKey($line->id)->update(['debit' => 1]),
            fn () => JournalLine::query()->whereKey($line->id)->delete(),
            fn () => Posting::query()->whereKey($posting->id)->update(['trans_date' => '2020-01-01']),
            fn () => Posting::query()->whereKey($posting->id)->delete(),
            fn () => Posting::query()->whereKey($posting->id)->update(['superseded_at' => now(), 'document_id' => 999]),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('the ledger accepted a rewrite');
            } catch (QueryException $e) {
                $this->assertStringContainsString('refused', $e->getMessage());
            }
        }

        // The one permitted change: marking it superseded.
        DB::transaction(fn () => Posting::query()->whereKey($posting->id)->update(['superseded_at' => now()]));
        $this->assertNotNull($posting->fresh()->superseded_at);
    }

    public function test_closed_months_refuse_postings_on_old_and_new_dates(): void
    {
        $lock = app(PeriodLock::class);
        $lock->close(2026, 9);
        $this->assertTrue($lock->isClosed('2026-09-15'));
        $this->assertFalse($lock->isClosed('2026-10-01'));

        try {
            app(PostingService::class)->post($this->voucher(100, '2026-09-30'));
            $this->fail('posting into a closed month must fail');
        } catch (PeriodClosedException) {
        }

        $voucher = $this->voucher(100, '2026-10-02');
        app(DocumentRepository::class)->created($voucher);
        try {
            app(DocumentRepository::class)->beforeUpdate($voucher, CarbonImmutable::parse('2026-09-01'));
            $this->fail('moving a document into a closed month must fail');
        } catch (PeriodClosedException) {
        }

        try {
            $lock->close(2026, 11);
            $this->fail('months close in order');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('October 2026', $e->getMessage());
        }
        $lock->close(2026, 10);
        try {
            $lock->reopen(2026, 9);
            $this->fail('only the last closed month reopens');
        } catch (\RuntimeException) {
        }
        $lock->reopen(2026, 10);
        $this->assertFalse($lock->isClosed('2026-10-15'));
    }

    public function test_the_repository_records_revisions_and_the_activity_log(): void
    {
        $repo = app(DocumentRepository::class);
        $voucher = $this->voucher(250_000);
        $repo->created($voucher);

        $before = $repo->beforeUpdate($voucher);
        $voucher->update(['description' => 'corrected']);
        $repo->updated($voucher->fresh(), $before);

        $revisions = DocumentRevision::query()->where('document_type', 'journal_voucher')->where('document_id', $voucher->id)->orderBy('revision')->get();
        $this->assertSame(['created', 'updated'], $revisions->pluck('action')->all());
        $this->assertSame('cash sale', $revisions[1]->before['header']['description']);
        $this->assertSame('corrected', $revisions[1]->after['header']['description']);
        $this->assertCount(2, $revisions[1]->after['lines']);
        $this->assertSame(2, Posting::query()->where('posting_key', $voucher->postingKey())->count());
        $this->assertDatabaseHas('audit_logs', ['document_type' => 'journal_voucher', 'document_id' => $voucher->id, 'action' => 'updated']);

        $repo->delete($voucher->fresh());
        $this->assertDatabaseMissing('journal_vouchers', ['id' => $voucher->id]);
        $this->assertSame(0, Posting::active()->where('posting_key', $voucher->postingKey())->count());
        $this->assertSame(0, AccountBalances::asOf()[$this->cash->id]);
    }

    public function test_another_users_document_needs_the_special_right(): void
    {
        $other = User::factory()->create();
        $voucher = $this->voucher(100);
        $voucher->update(['created_by' => $other->id]);
        $this->actingAs(User::factory()->create());

        $this->expectException(DocumentLockedException::class);
        app(DocumentRepository::class)->beforeUpdate($voucher->fresh());
    }

    public function test_opening_balances_post_against_opening_balance_equity(): void
    {
        $service = app(PostingService::class);
        $equity = Account::query()->where('no', '3300')->firstOrFail();
        $payable = Account::query()->where('no', '2100')->firstOrFail();

        $cashOpening = AccountOpeningBalance::query()->create(['account_id' => $this->cash->id, 'trans_date' => '2026-01-01', 'amount' => 10_000_000]);
        $payableOpening = AccountOpeningBalance::query()->create(['account_id' => $payable->id, 'trans_date' => '2026-01-01', 'amount' => 4_000_000]);
        $service->post($cashOpening);
        $service->post($payableOpening);

        $balances = AccountBalances::asOf();
        $this->assertSame(10_000_000, $balances[$this->cash->id]);
        $this->assertSame(4_000_000, $balances[$payable->id]);
        $this->assertSame(6_000_000, $balances[$equity->id]);

        // Changing the figure re-posts: the old lines no longer count.
        $cashOpening->update(['amount' => 12_000_000]);
        $service->post($cashOpening->fresh());
        $this->assertSame(12_000_000, AccountBalances::asOf()[$this->cash->id]);
        $this->assertSame(8_000_000, AccountBalances::asOf()[$equity->id]);
    }

    public function test_balances_roll_up_from_children_to_parents(): void
    {
        $parent = Account::query()->where('no', '1100')->firstOrFail();
        $this->cash->update(['parent_id' => $parent->id, 'is_sub' => true]);
        app(PostingService::class)->post($this->voucher(700));

        $balances = AccountBalances::asOf();
        $this->assertSame(700, $balances[$this->cash->id]);
        $this->assertSame(700, $balances[$parent->id]);
    }
}
