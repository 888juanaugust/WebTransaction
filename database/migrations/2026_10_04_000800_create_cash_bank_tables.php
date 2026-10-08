<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payments: money out of a cash or bank account to any accounts (K-02).
        Schema::create('cash_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('cheque_no', 40)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('payee', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount')->default(0);
            $table->boolean('is_printed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
            $table->index('bank_account_id');
        });

        Schema::create('cash_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
            $table->string('memo', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
        });

        // Receipts: other money in (K-03).
        Schema::create('cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('cheque_no', 40)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('payer', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount')->default(0);
            $table->boolean('is_printed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
            $table->index('bank_account_id');
        });

        Schema::create('cash_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
            $table->string('memo', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
        });

        // Bank transfers between two cash/bank accounts, with fees charged to either side (K-04).
        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('fees_total')->default(0);
            $table->string('description', 255)->nullable();
            $table->boolean('is_printed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });

        Schema::create('bank_transfer_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('charged_to', 4)->default('from'); // from | to
            $table->bigInteger('amount')->default(0);
            $table->string('memo', 255)->nullable();
        });

        // Bank statements imported from the bank's file, the basis of reconciliation (K-05).
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('source_file_name', 255)->nullable();
            $table->string('source_file_path', 255)->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->date('trans_date');
            $table->string('description', 255)->nullable();
            $table->string('reference', 80)->nullable();
            $table->bigInteger('amount'); // signed: money in positive, money out negative
            $table->bigInteger('balance')->nullable();
            $table->timestamps();
            $table->index(['bank_account_id', 'trans_date']);
        });

        // A reconciliation of one bank account over one period; closed when the
        // cleared book lines agree with the statement's ending balance.
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->bigInteger('statement_balance')->default(0);
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['bank_account_id', 'start_date', 'end_date']);
        });

        // One cleared journal line of the bank account. A line clears once; a
        // cleared line locks the document that posted it.
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_line_id')->unique()->constrained('journal_lines')->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->nullable()->constrained('bank_statement_lines')->nullOnDelete();
            $table->date('cleared_on')->nullable();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // Giros: post-dated cheques received or issued on a receipt or payment,
        // outstanding until they clear or bounce (K-06).
        Schema::create('giros', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3); // in | out
            $table->string('number', 40);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('party_id')->nullable();
            $table->string('party_name', 255)->nullable();
            $table->date('trans_date');
            $table->date('due_date')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->string('status', 12)->default('outstanding'); // outstanding | cleared | bounced
            $table->date('settled_on')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
            $table->index(['status', 'due_date']);
        });

        // The bank reconciliation items are a ledger of their own: a cleared line stays cleared.
        DB::unprepared('CREATE TRIGGER bank_reconciliation_items_append_only BEFORE UPDATE OR DELETE ON bank_reconciliation_items FOR EACH ROW EXECUTE FUNCTION ledger_append_only()');
    }

    public function down(): void
    {
        foreach (['giros', 'bank_reconciliation_items', 'bank_reconciliations', 'bank_statement_lines', 'bank_statements', 'bank_transfer_fees', 'bank_transfers', 'cash_receipt_lines', 'cash_receipts', 'cash_payment_lines', 'cash_payments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
