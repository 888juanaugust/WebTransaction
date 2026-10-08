<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A month of the books: open until the month-end process closes it.
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        // One row per posting of a document. A document re-posts by superseding
        // its active row and inserting a new one; nothing is ever rewritten.
        Schema::create('postings', function (Blueprint $table) {
            $table->id();
            $table->string('posting_key', 80);
            $table->unsignedInteger('revision')->default(1);
            $table->string('document_type', 60);
            $table->unsignedBigInteger('document_id');
            $table->date('trans_date');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['document_type', 'document_id']);
            $table->index('trans_date');
        });
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX postings_active_key ON postings (posting_key) WHERE superseded_at IS NULL;

            CREATE OR REPLACE FUNCTION postings_supersede_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'postings is append-only: DELETE refused' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.superseded_at IS NOT NULL
                   OR NEW.superseded_at IS NULL
                   OR NEW.posting_key IS DISTINCT FROM OLD.posting_key
                   OR NEW.revision IS DISTINCT FROM OLD.revision
                   OR NEW.document_type IS DISTINCT FROM OLD.document_type
                   OR NEW.document_id IS DISTINCT FROM OLD.document_id
                   OR NEW.trans_date IS DISTINCT FROM OLD.trans_date
                   OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                   OR NEW.posted_by IS DISTINCT FROM OLD.posted_by
                   OR NEW.posted_at IS DISTINCT FROM OLD.posted_at THEN
                    RAISE EXCEPTION 'postings may only be superseded: UPDATE refused' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER postings_supersede_only
                BEFORE UPDATE OR DELETE ON postings
                FOR EACH ROW EXECUTE FUNCTION postings_supersede_only();
        SQL);

        // The journal: one entry per posting, its lines balanced. Append-only.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_id')->constrained('postings')->restrictOnDelete();
            $table->string('number', 40);
            $table->date('trans_date');
            $table->string('source_type', 60);
            $table->string('source_number', 40)->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('trans_date');
            $table->index('source_type');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posting_id')->constrained('postings')->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->string('memo', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('trans_date');
            $table->index(['account_id', 'trans_date']);
            $table->index('posting_id');
        });

        // Every create, update and delete of a document, with its state before and after.
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 60);
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('revision');
            $table->string('action', 20);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['document_type', 'document_id']);
        });

        foreach (['journal_entries', 'journal_lines', 'document_revisions'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ledger_append_only();");
        }

        // An account's balance at the data start date; posted against Opening Balance Equity.
        Schema::create('account_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('accounts')->cascadeOnDelete();
            $table->date('trans_date');
            $table->bigInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_opening_balances');
        foreach (['document_revisions', 'journal_lines', 'journal_entries'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP TRIGGER IF EXISTS postings_supersede_only ON postings');
        Schema::dropIfExists('postings');
        DB::unprepared('DROP FUNCTION IF EXISTS postings_supersede_only()');
        Schema::dropIfExists('accounting_periods');
    }
};
