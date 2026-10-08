<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Preferences screen: one row per key, the value as JSON so every
        // type (switch, text, date, account id, list) fits one column.
        Schema::create('preferences', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        // The Activity Log: who did what to which record, when, from where.
        // Append-only: the trigger below refuses UPDATE and DELETE.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('document_type', 60)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('reference', 120)->nullable();
            $table->date('trans_date')->nullable();
            $table->string('ip', 45)->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_type', 'document_id']);
            $table->index('created_at');
            $table->index('user_id');
        });

        // Shared by every ledger table of the product (audit_logs now; journal
        // lines, stock movements, payment allocations, document revisions later):
        // rows are only ever inserted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % refused', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('preferences');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_append_only()');
    }
};
