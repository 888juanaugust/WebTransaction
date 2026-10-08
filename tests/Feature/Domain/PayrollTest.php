<?php

namespace Tests\Feature\Domain;

use App\Domain\Payroll\Art21Slips;
use App\Domain\Payroll\Bpjs;
use App\Domain\Payroll\IncomeKinds;
use App\Domain\Payroll\PayrollRun;
use App\Domain\Payroll\Pph21;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Enums\PtkpStatus;
use App\Domain\Shared\Enums\WorkStatus;
use App\Domain\Tax\Pph21Filings;
use App\Models\Company\Employee;
use App\Models\Company\PayrollEntry;
use App\Models\Company\SalaryComponent;
use App\Models\GeneralLedger\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Payroll worked out: TER each month, the year on the Art. 17 brackets in the last, BPJS within its caps, the slips for the tax office. */
class PayrollTest extends TestCase
{
    private SalaryComponent $salary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-10 10:00:00'));
        $this->enableAllModules();
        $this->seed();
        $this->actingAsAdmin();
        Storage::fake('local');
        app(Preferensi::class)->setMany([PreferensiKey::CompanyNpwp->value => '0012345678901000', PreferensiKey::Nitku->value => '0012345678901000000000']);
        $this->salary = SalaryComponent::query()->where('fee_type', 'salary')->firstOrFail();
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[(int) Account::query()->where('no', $no)->value('id')] ?? 0;
    }

    private function employee(string $number, PtkpStatus $status, int $salary, array $attributes = []): Employee
    {
        $employee = Employee::query()->create(array_merge(['number' => $number, 'name' => "Employee {$number}", 'nik_no' => '32010101900000'.substr($number, -2), 'position' => 'Staff',
            'withhold_income_tax' => true, 'work_status' => WorkStatus::Permanent, 'tax_status' => $status, 'join_date' => '2025-01-01', 'is_salesman' => false], $attributes));
        $employee->salaryComponents()->create(['salary_component_id' => $this->salary->id, 'amount' => $salary]);

        return $employee;
    }

    /** A payroll entry for the month, worked out by "Calculate payroll" and saved. */
    private function payroll(int $month, int $year = 2026): PayrollEntry
    {
        $date = CarbonImmutable::create($year, $month, 25);
        $entry = PayrollEntry::query()->create(['number' => sprintf('PAY-%02d%02d', $year % 100, $month), 'payment_type' => 'monthly', 'period_year' => $year, 'period_month' => $month,
            'trans_date' => $date->toDateString(), 'due_date' => $date->toDateString(), 'expense_payable_account_id' => Account::query()->where('no', '2230')->value('id'), 'created_by' => auth()->id()]);
        foreach (app(PayrollRun::class)->calculate($year, $month) as $i => $row) {
            $entry->lines()->create(IncomeKinds::normalise($row) + ['sort' => $i]);
        }
        $entry->refreshTotal();
        app(DocumentRepository::class)->created($entry);

        return $entry->fresh();
    }

    public function test_the_ter_tables_ptkp_and_brackets(): void
    {
        $this->assertSame(['0', '0.25'], [Pph21::terRate('A', 5_400_000), Pph21::terRate('A', 5_400_001)]);
        $this->assertSame(['2', '1.5', '1.5'], [Pph21::terRate('A', 10_000_000), Pph21::terRate('B', 10_000_000), Pph21::terRate('C', 10_000_000)]);
        $this->assertSame('34', Pph21::terRate('C', 2_000_000_000));
        $this->assertSame(['A', 'B', 'C'], [Pph21::category('K/0'), Pph21::category('K/1'), Pph21::category('K/3')]);
        $this->assertSame(200_000, Pph21::terTax(10_000_000, '2'));
        $this->assertSame([54_000_000, 58_500_000, 67_500_000, 72_000_000], [Pph21::ptkp('TK/0'), Pph21::ptkp('K/0'), Pph21::ptkp('K/2'), Pph21::ptkp('K/3')]);
        $this->assertSame(9_000_000, Pph21::article17(100_000_000), '5 % of 60 m, 15 % of 40 m');
        $this->assertSame(124_000_000, Pph21::article17(600_000_000));
        $this->assertSame(57_348_000, Pph21::pkp(115_848_999, 58_500_000), 'rounded down to the thousand');
        $this->assertSame(6_000_000, Pph21::biayaJabatan(125_448_000, 12), 'capped at 500 thousand a month');
    }

    public function test_bpjs_stays_within_its_caps(): void
    {
        $profile = ['bpjs_health' => true, 'bpjs_employment' => true, 'jp_participant' => true, 'jkk_rate' => '0.24'];
        $lines = collect(Bpjs::contributions(20_000_000, $profile, CarbonImmutable::parse('2026-11-30')))->groupBy('fee_type')->map->sum('amount');
        $this->assertSame(480_000 + 0, $lines['health_premium_employer'], '4 % of the 12 m cap');
        $this->assertSame(120_000, $lines['health_premium_employee']);
        $this->assertSame(740_000 + 221_726, $lines['pension_employer'], 'JHT 3.7 % of the wage, JP 2 % of the 11,086,300 cap');
        $this->assertSame(400_000 + 110_863, $lines['pension_employee']);
        $this->assertSame([48_000, 60_000], [$lines['accident_insurance'], $lines['death_insurance']]);
        $this->assertSame(10_547_400, Bpjs::pensionCap(CarbonImmutable::parse('2026-02-28')), 'the new cap starts in March');
    }

    public function test_a_year_of_payroll_withholds_ter_monthly_and_settles_the_year_in_december(): void
    {
        $employee = $this->employee('EMP-00011', PtkpStatus::K0, 10_000_000);
        $january = $this->payroll(1);

        // Taxable gross: 10 m salary + 400 k health (employer) + 24 k JKK + 30 k JKM = 10,454,000 → TER A 2.5 %.
        $taxLine = $january->lines()->whereNotNull('tax_method')->sole();
        $this->assertSame(['ter', 'A', '2.50', 10_454_000, 261_350], [$taxLine->tax_method, $taxLine->ter_category, $taxLine->ter_rate, $taxLine->taxable_gross, $taxLine->income_tax]);
        $this->assertSame(10_000_000 + 1_024_000, $january->gross_total, 'salary plus the employer\'s BPJS');
        $this->assertSame(9_338_650, $january->total, 'net: 10 m less tax 261,350 less BPJS 1 % + 2 % + 1 %');
        $this->assertSame(1_424_000, $this->balance('2240'), 'BPJS owed: both shares');
        $this->assertSame(1_024_000, $this->balance('6110'));
        $this->assertSame(261_350, $this->balance('2220'));
        $this->assertSame(9_338_650, $this->balance('2230'));

        foreach (range(2, 12) as $month) {
            $this->payroll($month);
        }
        // The year: gross 125,448,000 less occupational cost 6 m less pension 3.6 m = 115,848,000; PTKP K/0 58.5 m; PKP 57,348,000; 5 % = 2,867,400.
        $december = PayrollEntry::query()->where('period_month', 12)->sole()->lines()->whereNotNull('tax_method')->sole();
        $this->assertSame('annual', $december->tax_method);
        $this->assertSame(2_867_400 - 11 * 261_350, $december->income_tax, 'December gives back what the TER months withheld too much');

        $slip = app(Art21Slips::class)->slipFor($employee, 2026);
        $this->assertSame(125_448_000, $slip['annual']['gross']);
        $this->assertSame(120_000_000, $slip['rows']['salary']);
        $this->assertSame(5_448_000, $slip['rows']['insurance']);
        $this->assertSame(3_600_000, $slip['pension']);
        $this->assertSame([57_348_000, 2_867_400, 2_867_400, 0], [$slip['annual']['pkp'], $slip['tax_due'], $slip['withheld'], $slip['difference']]);
        $this->assertTrue($slip['complete']);
    }

    public function test_an_employee_leaving_mid_year_settles_the_year_in_the_last_month(): void
    {
        $employee = $this->employee('EMP-00012', PtkpStatus::TK0, 10_000_000, ['bpjs_health' => false, 'bpjs_employment' => false, 'exit_date' => '2026-06-15']);
        foreach (range(1, 6) as $month) {
            $this->payroll($month);
        }
        $this->payroll(7);
        $this->assertSame(0, (int) PayrollEntry::query()->where('period_month', 7)->sole()->lines()->count(), 'nothing after the exit month');

        // 2 % a month on 10 m; June: 60 m less 3 m occupational cost, less PTKP 54 m = 3 m taxable, 5 % = 150 k.
        $slip = app(Art21Slips::class)->slipFor($employee, 2026);
        $this->assertSame([6, 6, 3_000_000, 150_000], [$slip['month_end'], $slip['annual']['months'], $slip['annual']['pkp'], $slip['tax_due']]);
        $this->assertSame(150_000, $slip['withheld'], 'five months of 200 k, less 850 k given back in June');
        $this->assertTrue($slip['complete']);
    }

    public function test_the_slips_go_to_coretax(): void
    {
        $this->employee('EMP-00013', PtkpStatus::K1, 15_000_000, ['bpjs_health' => false, 'bpjs_employment' => false, 'npwp_no' => '3201010190000013']);
        foreach (range(1, 12) as $month) {
            $this->payroll($month);
        }

        $monthly = app(Pph21Filings::class)->exportMonthly(2026, 1);
        $this->assertSame('WH21-2601-0001', $monthly->number);
        $this->assertSame([1, 15_000_000, 900_000], [$monthly->document_count, $monthly->dpp_total, $monthly->tax_total], 'TER B 6 % on 15 m (14.95 m to 16.4 m)');
        $this->assertSame(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<MmPayrollBulk xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <TIN>0012345678901000</TIN>
  <ListOfMmPayroll>
    <MmPayroll>
      <TaxPeriodMonth>1</TaxPeriodMonth>
      <TaxPeriodYear>2026</TaxPeriodYear>
      <CounterpartOpt>Resident</CounterpartOpt>
      <CounterpartPassport></CounterpartPassport>
      <CounterpartTin>3201010190000013</CounterpartTin>
      <StatusTaxExemption>K/1</StatusTaxExemption>
      <Position>Staff</Position>
      <TaxCertificate>N/A</TaxCertificate>
      <TaxObjectCode>21-100-01</TaxObjectCode>
      <Gross>15000000</Gross>
      <Rate>6</Rate>
      <IDPlaceOfBusinessActivity>0012345678901000000000</IDPlaceOfBusinessActivity>
      <WithholdingDate>2026-01-31</WithholdingDate>
    </MmPayroll>
  </ListOfMmPayroll>
</MmPayrollBulk>

XML, Storage::disk('local')->get($monthly->file_path));
        $this->assertSame([], app(Pph21Filings::class)->monthlyRows(2026, 12), 'December goes on the A1, not a monthly slip');

        $annual = app(Pph21Filings::class)->exportAnnual(2026);
        $xml = simplexml_load_string(Storage::disk('local')->get($annual->file_path));
        $a1 = $xml->ListOfA1->A1;
        // 180 m less 6 m occupational cost = 174 m; PTKP K/1 63 m; PKP 111 m: 3 m + 7.65 m = 10,650,000.
        $this->assertSame(['1', '12', '180000000', '10650000', 'K/1'], [(string) $a1->TaxPeriodMonthStart, (string) $a1->TaxPeriodMonthEnd, (string) $a1->SalaryPensionJhtTht, (string) $a1->Article21IncomeTax, (string) $a1->StatusTaxExemption]);
        $this->assertSame(10_650_000, $annual->tax_total);
        $this->assertSame(10_650_000 - 11 * 900_000, (int) PayrollEntry::query()->where('period_month', 12)->sole()->tax_total, 'December withholds the rest');
        $this->assertStringStartsWith('WH21-2612-', $annual->number);
    }

    public function test_tax_is_worked_out_again_on_typed_pay_and_a_bonus_shares_the_month(): void
    {
        $employee = $this->employee('EMP-00014', PtkpStatus::TK0, 10_000_000, ['bpjs_health' => false, 'bpjs_employment' => false]);
        $this->payroll(3);
        $bonus = SalaryComponent::query()->where('fee_type', 'bonus')->firstOrFail();
        $rows = app(PayrollRun::class)->recalculateTax([['employee_id' => $employee->id, 'salary_component_id' => $bonus->id, 'fee_type' => 'bonus', 'gross_amount' => '10.000.000', 'contribution_amount' => 0]], 2026, 3);

        // March: 20 m in all → TER A 9 % (19.75 m to 24.15 m) = 1.8 m, less the 200 k the salary entry withheld.
        $this->assertSame([1_600_000, 'ter', '9', 20_000_000], [$rows[0]['income_tax'], $rows[0]['tax_method'], $rows[0]['ter_rate'], $rows[0]['taxable_gross']]);
        $this->assertSame(8_400_000, $rows[0]['net_amount']);
    }
}
