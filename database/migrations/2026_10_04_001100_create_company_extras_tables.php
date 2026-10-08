<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Budgets per account per month (G-08).
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('scope', 20)->default('general');
            $table->string('analyst_name', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['year', 'month', 'scope']);
        });
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
            $table->unique(['budget_id', 'account_id']);
        });
        Schema::create('budget_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('scope', 20)->default('general');
            $table->date('trans_date');
            $table->unsignedTinyInteger('from_month');
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->unsignedTinyInteger('to_month');
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });

        // Salary components and payroll entries: the journal of a payroll, not the payroll itself.
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('fee_type', 40)->default('salary');
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->string('payment_type', 12)->default('monthly'); // monthly | non_monthly
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('trans_date');
            $table->date('due_date');
            $table->foreignId('expense_payable_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('tax_payable_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->bigInteger('gross_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0); // net to pay
            $table->bigInteger('paid_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });
        Schema::create('payroll_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained('salary_components')->restrictOnDelete();
            $table->bigInteger('gross_amount')->default(0);
            $table->bigInteger('income_tax')->default(0);
            $table->bigInteger('net_amount')->default(0);
            $table->string('memo', 255)->nullable();
        });

        // Print layouts: a designable layout per document type (X-07).
        Schema::create('print_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('transaction_type', 40);
            $table->boolean('is_default')->default(false);
            $table->boolean('used_all_user')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestamps();
            $table->unique(['name', 'transaction_type']);
        });
        Schema::create('print_layout_users', function (Blueprint $table) {
            $table->foreignId('print_layout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['print_layout_id', 'user_id']);
        });

        // Transaction approvers: who must approve which documents, from what amount (X-05).
        Schema::create('transaction_approvers', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_type', 40);
            $table->bigInteger('min_amount')->default(0);
            $table->string('rule', 20)->default('any_one'); // any_one | at_least_two | all_in_order | all_any_order
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('transaction_approver_requesters', function (Blueprint $table) {
            $table->foreignId('transaction_approver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['transaction_approver_id', 'user_id']);
        });
        Schema::create('transaction_approver_groups', function (Blueprint $table) {
            $table->foreignId('transaction_approver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['transaction_approver_id', 'access_group_id']);
        });
        Schema::create('transaction_approver_users', function (Blueprint $table) {
            $table->foreignId('transaction_approver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['transaction_approver_id', 'user_id']);
        });

        // Recurring transactions: a template that becomes a document on schedule.
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->string('transaction_type', 40); // journal_voucher | cash_payment | cash_receipt
            $table->jsonb('template');
            $table->string('frequency', 10)->default('monthly'); // weekly | monthly | yearly
            $table->date('next_run_on');
            $table->date('last_run_on')->nullable();
            $table->date('end_on')->nullable();
            $table->string('status', 10)->default('active'); // active | paused | done
            $table->unsignedInteger('run_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Memorized transactions: a saved form, used again from the create page.
        Schema::create('memorized_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('transaction_type', 40);
            $table->jsonb('template');
            $table->boolean('used_all_user')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('memorized_transaction_users', function (Blueprint $table) {
            $table->foreignId('memorized_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['memorized_transaction_id', 'user_id']);
        });

        // Calendar: the company's own notes beside the dates the books produce.
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('starts_on');
        });
    }

    public function down(): void
    {
        foreach (['calendar_events', 'memorized_transaction_users', 'memorized_transactions', 'recurring_transactions', 'transaction_approver_users', 'transaction_approver_groups', 'transaction_approver_requesters', 'transaction_approvers', 'print_layout_users', 'print_layouts', 'payroll_entry_lines', 'payroll_entries', 'salary_components', 'budget_transfers', 'budget_lines', 'budgets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
