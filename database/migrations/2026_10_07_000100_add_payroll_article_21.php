<?php

use App\Domain\Pengaturan\Preferensi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll is calculated: each employee's pay setup (components and amounts),
 * BPJS participation and work-accident rate, and an exit date; payroll lines
 * keep their income kind, the contribution or deduction they carry and the
 * Art. 21 tax figures they were calculated with. The monthly and annual
 * withholding slips are exported as tax filings, numbered from their series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->date('exit_date')->nullable();
            $t->boolean('bpjs_health')->default(true);
            $t->boolean('bpjs_employment')->default(true);
            $t->boolean('jp_participant')->default(true);
            $t->decimal('jkk_rate', 5, 2)->default(0.24); // % of wages, by the work's risk group
        });
        Schema::create('employee_salary_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->bigInteger('amount')->default(0);
            $t->timestamps();
            $t->unique(['employee_id', 'salary_component_id']);
        });
        Schema::table('payroll_entry_lines', function (Blueprint $t) {
            $t->string('fee_type', 40)->nullable();
            $t->bigInteger('contribution_amount')->default(0); // credited to contribution_account_id: BPJS owed, or a deduction's account
            $t->foreignId('contribution_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->string('tax_method', 10)->nullable(); // ter | annual, on the line that carries the employee's tax
            $t->string('ter_category', 1)->nullable();
            $t->decimal('ter_rate', 6, 2)->nullable();
            $t->bigInteger('taxable_gross')->nullable();
        });
        Schema::table('tax_filings', fn (Blueprint $t) => $t->string('number', 40)->nullable());

        $this->seedAccounts();
    }

    /** BPJS payable and expense, and the payroll account preferences, for installations that already have a chart. */
    private function seedAccounts(): void
    {
        if (! DB::table('accounts')->exists()) {
            return; // not installed yet: the chart seeder adds them
        }
        foreach ([['2240', 'BPJS Payable', 'other_current_liability'], ['6110', 'Employee Benefits (BPJS)', 'expense']] as [$no, $name, $type]) {
            if (! DB::table('accounts')->where('no', $no)->exists()) {
                DB::table('accounts')->insert(['no' => $no, 'name' => $name, 'account_type' => $type, 'is_system' => false, 'is_active' => true, 'used_all_user' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $defaults = ['accounts.salary_expense' => '6100', 'accounts.salary_payable' => '2230', 'accounts.pph21_payable' => '2220', 'accounts.bpjs_payable' => '2240', 'accounts.bpjs_expense' => '6110'];
        foreach ($defaults as $key => $no) {
            $id = DB::table('accounts')->where('no', $no)->value('id');
            if ($id !== null && ! DB::table('preferences')->where('key', $key)->exists()) {
                DB::table('preferences')->insert(['key' => $key, 'value' => json_encode($id), 'updated_at' => now()]);
            }
        }
        app(Preferensi::class)->forget();
    }

    public function down(): void
    {
        Schema::table('tax_filings', fn (Blueprint $t) => $t->dropColumn('number'));
        Schema::table('payroll_entry_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('contribution_account_id');
            $t->dropColumn(['fee_type', 'contribution_amount', 'tax_method', 'ter_category', 'ter_rate', 'taxable_gross']);
        });
        Schema::dropIfExists('employee_salary_components');
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['exit_date', 'bpjs_health', 'bpjs_employment', 'jp_participant', 'jkk_rate']));
    }
};
