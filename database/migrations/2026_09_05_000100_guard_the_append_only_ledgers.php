<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ledgers stop being append-only by convention and start being append-only.
 *
 * Invariants 1 and 4 say stock and money are append-only: never
 * `UPDATE products SET stock = stock - n`, never mutate a payment row —
 * insert a reversing entry. The domain classes have honoured that from the
 * start. Nothing made them.
 *
 * Measured before writing this, by listening to every query the whole test
 * suite issues (2059 tests, the entire application exercised) and recording
 * every UPDATE or DELETE against these tables: **production code mutates one
 * row, once**, and it is `journal_entries.reversed_by_entry_id` — a
 * back-pointer to the reversal, no money in it. Every other hit was a test
 * fixture faking a legacy row. So this trigger forbids what the code already
 * does not do; what it changes is the next person, who writes
 * `->update(['amount_rupiah' => …])` on a Tuesday and gets an error instead
 * of a rewritten history nobody can see from the outside.
 *
 * That is the point of putting it here rather than in a model event or a
 * static check. A ledger's whole value is that the past cannot move, and a
 * guard living in the same application that does the writing is a guard that
 * a raw query, a migration, a console one-liner or an `updateOrCreate`
 * written in a hurry goes straight around. The database is the one layer
 * every writer has to pass through.
 *
 * **Not included: `order_events`.** It is append-only in the same spirit, but
 * it cascades from `orders`, and OrderEraser deletes draft and submitted
 * orders — a submitted order has events. Blocking it would break a feature
 * that is deliberate. `journal_lines` and `payment_allocations` also cascade,
 * from parents that are themselves guarded here and therefore never deleted.
 */
return new class extends Migration
{
    /**
     * The tables whose rows are the record, not a view of it.
     *
     * Both money ledgers and both of their allocation joins, the general
     * ledger and its lines, the stock ledger, and the audit log — an audit
     * log that can be edited is not an audit log.
     */
    private const TABLES = [
        'stock_movements',
        'payment_entries',
        'payment_allocations',
        'supplier_payment_entries',
        'supplier_payment_allocations',
        'journal_entries',
        'journal_lines',
        'audit_logs',
    ];

    public function up(): void
    {
        /*
         * The one exception, written out rather than left to a column list.
         *
         * Ledger::reverse posts a mirror entry and then stamps the original
         * with a pointer to it, so the reversal is navigable from both ends
         * and a second reversal can be refused. No figure moves. The rule is
         * narrow on purpose: the pointer must have been null (which is what
         * makes double reversal impossible), it must end up set, and the rest
         * of the row must be byte-for-byte what it was — compared as JSON so
         * that a column added later is covered by this without anybody
         * remembering to come back here.
         *
         * The row is addressed as JSON rather than by field name throughout,
         * and that is not a flourish. One function serves eight tables, and
         * plpgsql compiles `OLD.reversed_by_entry_id` when the statement runs
         * regardless of which branch would have used it — so naming the
         * column made every guarded table fail with "record old has no field
         * reversed_by_entry_id" instead of the message meant for it.
         * `to_jsonb(OLD)` is defined for any row type.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_is_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND TG_TABLE_NAME = 'journal_entries' THEN
                    IF to_jsonb(OLD)->>'reversed_by_entry_id' IS NULL
                       AND to_jsonb(NEW)->>'reversed_by_entry_id' IS NOT NULL
                       AND to_jsonb(NEW) - 'reversed_by_entry_id' = to_jsonb(OLD) - 'reversed_by_entry_id'
                    THEN
                        RETURN NEW;
                    END IF;
                END IF;

                RAISE EXCEPTION
                    'Tabel % hanya bisa ditambah: % ditolak. Catat baris pembalik, jangan ubah atau hapus baris lama.',
                    TG_TABLE_NAME, TG_OP
                    USING ERRCODE = '23001';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (self::TABLES as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_append_only
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION ledger_is_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS ledger_is_append_only();');
    }
};
