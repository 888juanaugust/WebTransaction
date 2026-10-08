<?php

namespace Tests\Feature\Domain;

use App\Domain\CashBank\StatementImporter;
use App\Domain\FixedAssets\AssetFromBill;
use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\FixedAssets\FiscalDepreciator;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Reports\CashAndAssetReports;
use App\Domain\Reports\Period;
use App\Filament\Resources\FixedAssets\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Resources\Purchasing\PurchaseInvoices\Pages\EditPurchaseInvoice;
use App\Models\CashBank\CashPayment;
use App\Models\CashBank\CashReceipt;
use App\Models\Company\TaxCode;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\FiscalAssetCategory;
use App\Models\FixedAssets\FixedAsset;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\ExpenseAccrual;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseInvoice;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** Tax per line on cash documents, bank statements from Excel, assets from purchase invoices, and the tax books' depreciation. */
class CashBankTaxAndAssetsTest extends TestCase
{
    private DocumentRepository $docs;

    private TaxCode $vat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();
        $this->docs = app(DocumentRepository::class);
        $this->vat = TaxCode::default();
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[$this->account($no)] ?? 0;
    }

    public function test_a_payment_line_with_vat_books_the_expense_net_and_the_vat_in(): void
    {
        $payment = CashPayment::query()->create(['number' => 'PAY-1', 'trans_date' => '2026-11-16', 'bank_account_id' => $this->account('1102'), 'created_by' => auth()->id()]);
        $payment->lines()->create(['sort' => 0, 'account_id' => $this->account('6200'), 'amount' => 1_000_000, 'tax_code_id' => $this->vat->id, 'tax_invoice_number' => '04002600000001']);
        $payment->refreshTotal();
        $this->docs->created($payment);

        $this->assertSame(1_110_000, $payment->fresh()->amount, '12 % on 11/12 of the price, added');
        $this->assertSame(110_000, (int) $payment->lines()->first()->tax_amount);
        $this->assertSame(1_000_000, $this->balance('6200'));
        $this->assertSame(110_000, $this->balance('1400'), 'VAT in');
        $this->assertSame(-1_110_000, $this->balance('1102'));

        $inclusive = CashPayment::query()->create(['number' => 'PAY-2', 'trans_date' => '2026-11-16', 'bank_account_id' => $this->account('1102'), 'inclusive_tax' => true, 'created_by' => auth()->id()]);
        $inclusive->lines()->create(['sort' => 0, 'account_id' => $this->account('6200'), 'amount' => 1_110_000, 'tax_code_id' => $this->vat->id]);
        $inclusive->refreshTotal();
        $this->docs->created($inclusive);
        $this->assertSame(1_110_000, $inclusive->fresh()->amount, 'the amount already holds the tax');
        $this->assertSame(2_000_000, $this->balance('6200'));
        $this->assertSame(220_000, $this->balance('1400'));
    }

    public function test_receipts_book_vat_out_and_an_accrual_owes_its_vat_until_paid(): void
    {
        $receipt = CashReceipt::query()->create(['number' => 'REC-1', 'trans_date' => '2026-11-16', 'bank_account_id' => $this->account('1102'), 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'account_id' => $this->account('7100'), 'amount' => 2_000_000, 'tax_code_id' => $this->vat->id]);
        $receipt->refreshTotal();
        $this->docs->created($receipt);
        $this->assertSame(2_220_000, $this->balance('1102'));
        $this->assertSame(220_000, $this->balance('2200'), 'VAT out');
        $this->assertSame(2_000_000, $this->balance('7100'), 'the income without its tax');

        $accrual = ExpenseAccrual::query()->create(['number' => 'ACR-1', 'trans_date' => '2026-11-16', 'due_date' => '2026-12-16', 'payable_account_id' => $this->account('2230'), 'created_by' => auth()->id()]);
        $accrual->lines()->create(['sort' => 0, 'account_id' => $this->account('6200'), 'amount' => 500_000, 'tax_code_id' => $this->vat->id]);
        $accrual->refreshTotal();
        $this->docs->created($accrual);
        $this->assertSame(555_000, $accrual->fresh()->total, 'what is owed includes the VAT');
        $this->assertSame(55_000, $this->balance('1400'));
    }

    public function test_a_bank_statement_reads_from_an_excel_workbook(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'stmt').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($file);
        $writer->addRow(Row::fromValues(['Account statement']));
        $writer->addRow(Row::fromValues(['Date', 'Description', 'Debit', 'Credit', 'Balance']));
        $writer->addRow(Row::fromValues(['03/11/2026', 'Transfer in', '', 1_500_000, 1_500_000]));
        $writer->addRow(Row::fromValues(['04/11/2026', 'Bank charge', 6_500, '', 1_493_500]));
        $writer->close();

        $rows = app(StatementImporter::class)->rows($file);
        @unlink($file);
        $this->assertSame([['2026-11-03', 1_500_000], ['2026-11-04', -6_500]], array_map(fn ($r) => [$r['trans_date'], $r['amount']], $rows));
    }

    public function test_an_asset_recorded_from_a_bill_line_moves_its_cost_and_locks_the_bill(): void
    {
        $vendor = $this->sampleVendor();
        $press = $this->sampleItem(['number' => 'NI-1', 'name' => 'Hydraulic press', 'item_type' => 'non_inventory']);
        $bill = PurchaseInvoice::query()->create(['number' => 'BILL-1', 'trans_date' => '2026-11-02', 'vendor_id' => $vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $bill->lines()->create(['sort' => 0, 'item_id' => $press->id, 'quantity' => 1, 'unit_id' => $press->unit1_id, 'base_quantity' => 1, 'unit_price' => 20_000_000, 'warehouse_id' => Warehouse::default()->id]);
        $bill->refreshTotal();
        $this->docs->created($bill);
        $line = $bill->lines()->first();
        $expenseAccount = AssetFromBill::prefill($line)['expenditures'][0]['account_id'];
        $expensedBefore = AccountBalances::asOf()[$expenseAccount] ?? 0;

        Livewire::test(EditPurchaseInvoice::class, ['record' => $bill->getRouteKey()])->assertActionVisible('recordAsset');
        Livewire::withQueryParams(['bill_line' => $line->id])->test(CreateFixedAsset::class)
            ->assertSet('data.name', 'Hydraulic press')
            ->assertSet('data.purchase_invoice_line_id', $line->id)
            ->assertSet('data.trans_date', fn ($date) => str_starts_with((string) $date, '2026-11-02'));

        $category = AssetCategory::query()->where('name', 'Equipment')->firstOrFail();
        $prefill = AssetFromBill::prefill($line);
        $asset = FixedAsset::query()->create(collect($prefill)->except('expenditures')->all() + ['number' => 'FA-1', 'depreciation_method' => DepreciationMethod::StraightLine,
            'asset_account_id' => $category->asset_account_id, 'accumulated_depreciation_account_id' => $category->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => $category->depreciation_expense_account_id, 'useful_life_months' => 48, 'asset_category_id' => $category->id, 'created_by' => auth()->id()]);
        $asset->expenditures()->createMany($prefill['expenditures']);
        $asset->refreshTotal();
        $this->docs->created($asset);

        $this->assertSame($expensedBefore - 20_000_000, AccountBalances::asOf()[$expenseAccount] ?? 0, 'the cost leaves the expense account');
        $this->assertSame(20_000_000, AccountBalances::asOf()[$category->asset_account_id] ?? 0);
        $this->assertStringContainsString('FA-1', (string) $this->docs->lockReason($bill->fresh()));
        Livewire::test(EditPurchaseInvoice::class, ['record' => $bill->getRouteKey()])->assertActionHidden('recordAsset');

        $this->docs->delete($asset->fresh());
        $this->assertNull($this->docs->lockReason($bill->fresh()));
    }

    public function test_the_tax_books_depreciate_by_the_fiscal_group_with_the_rest_in_the_last_year(): void
    {
        $category = AssetCategory::query()->where('name', 'Equipment')->firstOrFail();
        $straight = FiscalAssetCategory::query()->where('name', 'Group I (4 years)')->firstOrFail();
        $declining = FiscalAssetCategory::query()->create(['name' => 'Group I declining', 'depreciation_method' => 'declining_balance', 'useful_life_years' => 4, 'rate_percent' => 50]);
        $make = fn (string $number, FiscalAssetCategory $group) => FixedAsset::query()->create(['number' => $number, 'name' => $number, 'trans_date' => '2026-04-01', 'usage_date' => '2026-04-01', 'depreciation_method' => DepreciationMethod::StraightLine,
            'asset_account_id' => $category->asset_account_id, 'accumulated_depreciation_account_id' => $category->accumulated_depreciation_account_id, 'depreciation_expense_account_id' => $category->depreciation_expense_account_id,
            'useful_life_months' => 48, 'asset_category_id' => $category->id, 'fiscal' => true, 'fiscal_asset_category_id' => $group->id, 'cost' => 120_000_000]);

        $years = FiscalDepreciator::years($make('FA-SL', $straight));
        $this->assertSame([22_500_000, 30_000_000, 30_000_000, 30_000_000, 7_500_000], array_column($years, 'depreciation'), 'nine months in the first year; the last three months take the rest');
        $years = FiscalDepreciator::years($make('FA-DB', $declining));
        $this->assertSame([45_000_000, 37_500_000, 18_750_000, 9_375_000, 9_375_000], array_column($years, 'depreciation'));
        $this->assertSame(0, end($years)['closing']);

        $rows = collect(CashAndAssetReports::depreciationSchedule(new Period('2026-01-01', '2026-12-31'), null, 'fiscal'))->keyBy('number');
        $this->assertSame(22_500_000, $rows['FA-SL']['period']);
        $this->assertSame(97_500_000, $rows['FA-SL']['book_value']);
    }
}
