<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Audit\AuditLogger;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\PaymentEntry;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The past does not move, and now the database is the one saying so.
 *
 * Invariants 1 and 4 have always been kept by the domain classes and by
 * nothing else. Before writing the trigger I listened to every query the
 * whole suite issues — 2059 tests, the application exercised end to end — and
 * recorded every UPDATE or DELETE against these eight tables. Production code
 * mutated one row, once: `journal_entries.reversed_by_entry_id`, a pointer to
 * the reversal with no money in it. Everything else was a fixture faking a
 * legacy row.
 *
 * So none of this changes what the code does. It changes what happens when
 * somebody writes `->update(['amount_rupiah' => …])` next year, or fixes a
 * figure from `php artisan tinker` at 23:00 because a customer is on the
 * phone. A ledger whose rows can be edited is a ledger that cannot be used as
 * evidence, and the layer that has to say no is the one every writer passes
 * through.
 */
class LedgerAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->finance = User::factory()->finance()->create();
    }

    /**
     * Run a write that the database should refuse, and hand back the refusal.
     *
     * Inside its own transaction because a rejected statement poisons the one
     * it was issued in: under RefreshDatabase that is the test's own outer
     * transaction, and every later query in the test would fail with "current
     * transaction is aborted" rather than whatever it was actually asserting.
     * The nested call is a savepoint, so only the offending statement is
     * unwound.
     */
    private function refused(callable $write): QueryException
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Basis data menerima perubahan yang seharusnya ditolak.');
    }

    private function assertLedgerRefusal(QueryException $e, string $table, string $op): void
    {
        $this->assertSame('23001', (string) $e->getCode(), 'SQLSTATE pelanggaran append-only');
        $this->assertStringContainsString("Tabel {$table} hanya bisa ditambah", $e->getMessage());
        $this->assertStringContainsString("{$op} ditolak", $e->getMessage());
        $this->assertStringContainsString('Catat baris pembalik', $e->getMessage());
    }

    // --- the eight tables --------------------------------------------------

    public function test_the_guard_is_on_every_ledger_and_fires_before_both_verbs(): void
    {
        /*
         * Read from the schema rather than retyped here, and asserted as a
         * whole set: a ledger that loses its trigger to a later migration, and
         * a new ledger added without one, both show up as this failing rather
         * than as a gap nobody is looking at.
         *
         * `order_events` is deliberately absent — see the migration. It is
         * append-only in spirit but cascades from `orders`, and OrderEraser
         * deletes draft and submitted orders, which have events.
         */
        $guarded = DB::table('pg_trigger')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')
            ->where('pg_trigger.tgname', 'like', '%\_append\_only')
            ->where('pg_trigger.tgisinternal', false)
            ->pluck('pg_class.relname')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'audit_logs',
            'journal_entries',
            'journal_lines',
            'payment_allocations',
            'payment_entries',
            'stock_movements',
            'supplier_payment_allocations',
            'supplier_payment_entries',
        ], $guarded);

        foreach ($guarded as $table) {
            $definition = DB::selectOne(
                'SELECT pg_get_triggerdef(t.oid) AS def
                 FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                 WHERE c.relname = ? AND t.tgname = ?',
                [$table, "{$table}_append_only"],
            )->def;

            // BEFORE, so nothing is written and then undone; and both verbs,
            // because a row that can be deleted did not need editing. The
            // order is Postgres's own — it normalises the verbs it prints back.
            $this->assertStringContainsString('BEFORE DELETE OR UPDATE', $definition, $table);
            $this->assertStringContainsString('FOR EACH ROW', $definition, $table);
        }
    }

    // --- money -------------------------------------------------------------

    private function payment(int $amount = 10_000_000): PaymentEntry
    {
        return app(PaymentLedger::class)->recordManualPayment(
            company: Company::factory()->creditLimit(500_000_000)->create(),
            amountRupiah: $amount,
            actor: $this->finance,
        );
    }

    public function test_a_recorded_payment_cannot_have_its_amount_edited(): void
    {
        $entry = $this->payment();

        /*
         * The invariant's own words: never mutate a payment row, insert a
         * reversing entry. This is the line that used to be a sentence in
         * CLAUDE.md and is now a constraint.
         */
        $e = $this->refused(fn () => PaymentEntry::query()
            ->whereKey($entry->id)
            ->update(['amount_rupiah' => 1_000_000]));

        $this->assertLedgerRefusal($e, 'payment_entries', 'UPDATE');
        $this->assertSame(10_000_000, (int) $entry->fresh()->amount_rupiah);
    }

    public function test_a_payment_cannot_be_made_to_disappear(): void
    {
        $entry = $this->payment();

        $this->assertLedgerRefusal(
            $this->refused(fn () => PaymentEntry::query()->whereKey($entry->id)->delete()),
            'payment_entries',
            'DELETE',
        );

        $this->assertNotNull($entry->fresh());
    }

    // --- stock -------------------------------------------------------------

    public function test_a_stock_movement_cannot_be_edited_after_the_fact(): void
    {
        $movement = app(StockLedger::class)->record(
            sku: 'APPEND-ONLY-1',
            warehouseId: Warehouse::factory()->create()->id,
            qtySigned: 200,
            reason: MovementReason::Penerimaan,
        );

        // The literal shape invariant 1 forbids: correcting a quantity in
        // place instead of booking the correction as its own movement.
        $this->assertLedgerRefusal(
            $this->refused(fn () => StockMovement::query()->whereKey($movement->id)->update(['qty_signed' => 20])),
            'stock_movements',
            'UPDATE',
        );

        $this->assertSame(200, (int) $movement->fresh()->qty_signed);
    }

    // --- the audit log -----------------------------------------------------

    public function test_the_audit_log_cannot_be_tidied_up(): void
    {
        app(AuditLogger::class)->log(
            action: 'uji_append_only',
            oldValue: ['harga' => 1_000_000],
            newValue: ['harga' => 900_000],
            actor: $this->finance,
        );

        $row = AuditLog::query()->where('action', 'uji_append_only')->sole();

        /*
         * The one table where the attacker and the auditor are the same
         * person. Every money-affecting action writes here, and an audit log
         * whose rows can be edited or removed answers whatever the last
         * person to touch it wanted it to say.
         */
        $this->assertLedgerRefusal(
            $this->refused(fn () => AuditLog::query()->whereKey($row->id)->update(['action' => 'sesuatu_yang_lain'])),
            'audit_logs',
            'UPDATE',
        );

        $this->assertLedgerRefusal(
            $this->refused(fn () => AuditLog::query()->whereKey($row->id)->delete()),
            'audit_logs',
            'DELETE',
        );

        $this->assertSame('uji_append_only', $row->fresh()->action);
    }

    // --- the one exception -------------------------------------------------

    private function journal(): JournalEntry
    {
        return app(Ledger::class)->postManual(
            JournalDraft::manual('Uji append-only')
                ->debit(AccountCode::KAS, 1_000_000)
                ->kredit(AccountCode::MODAL_DISETOR, 1_000_000),
            $this->finance,
        );
    }

    public function test_reversing_a_journal_entry_still_works(): void
    {
        $entry = $this->journal();

        /*
         * The single mutation production code performs, and the reason the
         * trigger has an exception at all: the reversal is navigable from both
         * ends, and the pointer is what makes a second reversal refusable.
         */
        $reversal = app(Ledger::class)->reverse($entry, $this->finance, 'Uji');

        $this->assertSame($reversal->id, $entry->fresh()->reversed_by_entry_id);
        $this->assertSame($entry->id, $reversal->reverses_entry_id);
    }

    public function test_the_exception_covers_only_that_pointer_and_only_once(): void
    {
        $entry = $this->journal();

        // Not a door for the rest of the row.
        $this->assertLedgerRefusal(
            $this->refused(fn () => JournalEntry::query()->whereKey($entry->id)->update([
                'reversed_by_entry_id' => $entry->id,
                'keterangan' => 'Bukan ini yang terjadi',
            ])),
            'journal_entries',
            'UPDATE',
        );

        $reversal = app(Ledger::class)->reverse($entry, $this->finance, 'Uji');

        /*
         * And not a door for a second time. Ledger::reverse refuses this in
         * PHP under a lock; the trigger refuses it under the row itself, so
         * the pointer cannot be repointed at a different reversal by anything
         * that goes around the domain class.
         */
        $this->assertLedgerRefusal(
            $this->refused(fn () => JournalEntry::query()->whereKey($entry->id)->update([
                'reversed_by_entry_id' => $entry->id,
            ])),
            'journal_entries',
            'UPDATE',
        );

        $this->assertSame($reversal->id, $entry->fresh()->reversed_by_entry_id);
    }

    public function test_a_journal_line_cannot_be_edited_even_while_its_entry_can_be_pointed(): void
    {
        $entry = $this->journal();

        // The exception is scoped to one column of one table; the lines
        // carrying the actual figures have no exception at all.
        $this->assertLedgerRefusal(
            $this->refused(fn () => DB::table('journal_lines')
                ->where('journal_entry_id', $entry->id)
                ->update(['debit_rupiah' => 5])),
            'journal_lines',
            'UPDATE',
        );
    }
}
