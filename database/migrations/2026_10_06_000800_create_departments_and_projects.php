<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments (a tree) and projects (for a customer, with dates and a
 * status) tag journal lines, so income and expense can be read per
 * department or project. The GL documents carry them on the header (the
 * default) and per line.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TAGGED = [
        'journal_lines',
        'journal_vouchers', 'journal_voucher_lines',
        'expense_accruals', 'expense_accrual_lines',
        'cash_payments', 'cash_payment_lines',
        'cash_receipts', 'cash_receipt_lines',
        'payroll_entries', 'payroll_entry_lines',
    ];

    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->foreignId('parent_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 12)->default('planned'); // planned | active | finished | cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        foreach (self::TAGGED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->index()->constrained('departments')->restrictOnDelete();
                $table->foreignId('project_id')->nullable()->index()->constrained('projects')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TAGGED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('department_id');
                $table->dropConstrainedForeignId('project_id');
            });
        }
        Schema::dropIfExists('projects');
        Schema::dropIfExists('departments');
    }
};
