<?php

namespace Tests\Feature\Domain;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Reports\FinancialStatements;
use App\Domain\Reports\Period;
use App\Filament\Pages\GeneralLedger\AccountHistory;
use App\Filament\Pages\Reports\IncomeStatement;
use App\Filament\Resources\CashBank\CashPayments\Pages\CreateCashPayment;
use App\Filament\Resources\Company\Departments\DepartmentResource;
use App\Filament\Resources\Company\Projects\ProjectResource;
use App\Filament\Resources\GeneralLedger\JournalVouchers\Pages\CreateJournalVoucher;
use App\Models\CashBank\CashPayment;
use App\Models\Company\Department;
use App\Models\Company\Project;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
use App\Models\GeneralLedger\JournalVoucher;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Departments (a tree) and projects tag journal lines; the income statement and the ledger reports filter by them. */
class DepartmentsProjectsTest extends TestCase
{
    private Department $sales;

    private Department $north;

    private Department $admin;

    private Project $fitOut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
    }

    private function switchOn(): void
    {
        app(Preferensi::class)->setMany([PreferensiKey::Department->value => true, PreferensiKey::Project->value => true]);
        $this->freshRequest();
        $this->sales = $this->sampleDepartment();
        $this->north = $this->sampleDepartment(['code' => 'D-SALES-N', 'name' => 'Sales north', 'parent_id' => $this->sales->id]);
        $this->admin = $this->sampleDepartment(['code' => 'D-ADM', 'name' => 'Administration']);
        $this->fitOut = $this->sampleProject();
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    /** Rent paid from the bank: the header names the department; a line may name its own. */
    private function payment(string $number, int $amount, ?int $headerDepartment, ?int $lineDepartment = null, ?int $project = null): CashPayment
    {
        $payment = CashPayment::query()->create(['number' => $number, 'trans_date' => '2026-11-16', 'bank_account_id' => $this->account('1102'), 'department_id' => $headerDepartment, 'created_by' => auth()->id()]);
        $payment->lines()->create(['sort' => 0, 'account_id' => $this->account('6200'), 'amount' => $amount, 'department_id' => $lineDepartment, 'project_id' => $project]);
        $payment->refreshTotal();
        app(DocumentRepository::class)->created($payment);

        return $payment;
    }

    /** @return array<string, int> the income statement's amounts by account number and total id */
    private function incomeStatement(?int $department = null, ?int $project = null): array
    {
        $out = [];
        foreach (FinancialStatements::incomeStatement(new Period('2026-11-01', '2026-11-30', null, $department, $project)) as $row) {
            $out[$row['no'] !== '' ? $row['no'] : $row['id']] = $row['amount'];
        }

        return $out;
    }

    public function test_the_modules_are_off_until_switched_on(): void
    {
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('departments'));
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('projects'));
        $this->get(DepartmentResource::getUrl('index'))->assertForbidden();
        $this->get(ProjectResource::getUrl('index'))->assertForbidden();
        Livewire::test(CreateJournalVoucher::class)->assertFormFieldDoesNotExist('department_id')->assertFormFieldDoesNotExist('project_id');
        Livewire::test(IncomeStatement::class)->assertFormFieldDoesNotExist('department_id');

        $this->switchOn();
        $this->get(DepartmentResource::getUrl('index'))->assertOk()->assertSee('Sales north');
        $this->get(ProjectResource::getUrl('index'))->assertOk()->assertSee('Warehouse fit-out');
        Livewire::test(CreateJournalVoucher::class)->assertFormFieldExists('department_id')->assertFormFieldExists('project_id');
        Livewire::test(CreateCashPayment::class)->assertFormFieldExists('department_id');
        Livewire::test(IncomeStatement::class)->assertFormFieldExists('department_id')->assertFormFieldExists('project_id');
        Livewire::test(AccountHistory::class)->assertFormFieldExists('department_id');
    }

    public function test_journal_lines_carry_the_line_tag_else_the_header_tag(): void
    {
        $this->switchOn();
        $payment = $this->payment('PAY-1', 1_000_000, $this->sales->id, $this->north->id, $this->fitOut->id);

        $lines = JournalLine::query()->active()->whereHas('entry', fn ($q) => $q->where('source_number', 'PAY-1'))->get()->keyBy('account_id');
        $this->assertSame($this->north->id, $lines[$this->account('6200')]->department_id, 'the line names its own department');
        $this->assertSame($this->fitOut->id, $lines[$this->account('6200')]->project_id);
        $this->assertSame($this->sales->id, $lines[$this->account('1102')]->department_id, 'the bank leg takes the header\'s');
        $this->assertNull($lines[$this->account('1102')]->project_id);

        $voucher = JournalVoucher::query()->create(['number' => 'JV-1', 'trans_date' => '2026-11-16', 'project_id' => $this->fitOut->id, 'created_by' => auth()->id()]);
        $voucher->lines()->createMany([
            ['sort' => 0, 'account_id' => $this->account('6300'), 'debit' => 250_000, 'credit' => 0, 'department_id' => $this->admin->id],
            ['sort' => 1, 'account_id' => $this->account('1101'), 'debit' => 0, 'credit' => 250_000],
        ]);
        $voucher->refreshTotal();
        app(DocumentRepository::class)->created($voucher);
        $lines = JournalLine::query()->active()->whereHas('entry', fn ($q) => $q->where('source_number', 'JV-1'))->get()->keyBy('account_id');
        $this->assertSame([$this->admin->id, $this->fitOut->id], [$lines[$this->account('6300')]->department_id, $lines[$this->account('6300')]->project_id]);
        $this->assertSame([null, $this->fitOut->id], [$lines[$this->account('1101')]->department_id, $lines[$this->account('1101')]->project_id]);

        $docs = app(DocumentRepository::class);
        $before = $docs->beforeUpdate($payment->fresh());
        $payment->lines()->update(['department_id' => $this->admin->id]);
        $docs->updated($payment->fresh(), $before);
        $line = JournalLine::query()->active()->whereHas('entry', fn ($q) => $q->where('source_number', 'PAY-1'))->where('account_id', $this->account('6200'))->sole();
        $this->assertSame($this->admin->id, $line->department_id, 'a re-post books the new tag');
    }

    public function test_the_income_statement_filters_by_department_with_its_children_and_by_project(): void
    {
        $this->switchOn();
        $this->payment('PAY-1', 1_000_000, $this->sales->id);
        $this->payment('PAY-2', 400_000, $this->sales->id, $this->north->id, $this->fitOut->id);
        $this->payment('PAY-3', 250_000, $this->admin->id);
        $this->payment('PAY-4', 50_000, null);

        $this->assertSame(1_700_000, $this->incomeStatement()['6200']);
        $this->assertSame(1_400_000, $this->incomeStatement($this->sales->id)['6200'], 'sales with sales north under it');
        $this->assertSame(400_000, $this->incomeStatement($this->north->id)['6200']);
        $this->assertSame(250_000, $this->incomeStatement($this->admin->id)['6200']);
        $this->assertSame(-400_000, $this->incomeStatement(null, $this->fitOut->id)['t-net']);
        $this->assertSame(-400_000, $this->incomeStatement($this->sales->id, $this->fitOut->id)['t-net']);

        Livewire::test(IncomeStatement::class)
            ->set('filters.from', '2026-11-01')
            ->set('filters.department_id', $this->north->id)
            ->assertSee('400.000')
            ->assertDontSee('1.400.000');
        Livewire::test(AccountHistory::class)
            ->set('filters.account_id', $this->account('6200'))
            ->set('filters.from', '2026-11-01')
            ->set('filters.department_id', $this->admin->id)
            ->assertSee('PAY-3')
            ->assertDontSee('PAY-1');
    }

    public function test_switched_off_the_filters_are_ignored_and_the_tags_kept(): void
    {
        $this->switchOn();
        $this->payment('PAY-1', 1_000_000, $this->sales->id);
        $this->payment('PAY-2', 250_000, $this->admin->id);

        app(Preferensi::class)->setMany([PreferensiKey::Department->value => false, PreferensiKey::Project->value => false]);
        $this->freshRequest();
        Livewire::test(IncomeStatement::class)
            ->set('filters.from', '2026-11-01')
            ->set('filters.department_id', $this->admin->id)
            ->assertSee('1.250.000');
        $this->assertSame(2, JournalLine::query()->active()->where('department_id', $this->sales->id)->count(), 'the tags stay on the books');
    }
}
