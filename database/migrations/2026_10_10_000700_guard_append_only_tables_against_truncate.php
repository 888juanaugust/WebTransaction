<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only tables refuse TRUNCATE as well as UPDATE and DELETE (row triggers never see a TRUNCATE). And a
 * cleared bank line keeps its statement line: deleting a statement line that cleared something is refused outright,
 * where it used to try to empty the cleared line's reference, which the append-only trigger then refused.
 */
return new class extends Migration
{
    private const TABLES = ['audit_logs', 'postings', 'journal_entries', 'journal_lines', 'document_revisions', 'stock_movements',
        'payment_allocations', 'bank_reconciliation_items', 'approval_decisions', 'tax_invoice_mails'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();");
        }
        Schema::table('bank_reconciliation_items', function (Blueprint $table) {
            $table->dropForeign(['bank_statement_line_id']);
            $table->foreign('bank_statement_line_id')->references('id')->on('bank_statement_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliation_items', function (Blueprint $table) {
            $table->dropForeign(['bank_statement_line_id']);
            $table->foreign('bank_statement_line_id')->references('id')->on('bank_statement_lines')->nullOnDelete();
        });
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table}");
        }
    }
};
