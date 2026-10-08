<?php

namespace Tests\Feature\Domain;

use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\FixedAssets\DepreciationRun;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Reports\CashAndAssetReports;
use App\Domain\Reports\FinancialStatements;
use App\Domain\Reports\InventoryReports;
use App\Domain\Reports\Period;
use App\Domain\Reports\TradeReports;
use App\Models\CashBank\CashReceipt;
use App\Models\Company\TaxCode;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\FixedAsset;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    private Item $item;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-30 09:00:00');
        CarbonImmutable::setTestNow('2026-11-30 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $docs = app(DocumentRepository::class);
        $acc = fn (string $no) => (int) Account::query()->where('no', $no)->value('id');

        // Capital in, stock in, a sale on credit, an asset bought and depreciated.
        $receipt = CashReceipt::query()->create(['number' => 'CR-1', 'trans_date' => '2026-10-01', 'bank_account_id' => $acc('1102'), 'payer' => 'Owner', 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'account_id' => $acc('3100'), 'amount' => 50_000_000]);
        $receipt->refreshTotal();
        $docs->created($receipt);

        $this->customer = $this->sampleCustomer();
        $this->item = $this->sampleItem();
        $opening = InventoryAdjustment::query()->create(['number' => 'ADJ-OPEN', 'trans_date' => '2026-10-02', 'created_by' => auth()->id()]);
        $opening->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 20, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 20, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $docs->created($opening);

        $invoice = SalesInvoice::query()->create(['number' => 'INV-1', 'trans_date' => '2026-11-05', 'customer_id' => $this->customer->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 10, 'unit_price' => 150_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        $docs->created($invoice);

        $order = SalesOrder::query()->create(['number' => 'SO-1', 'trans_date' => '2026-11-10', 'customer_id' => $this->customer->id, 'taxable' => false, 'inclusive_tax' => false, 'approval_status' => 'approved', 'created_by' => auth()->id()]);
        $order->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 4, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 4, 'unit_price' => 150_000, 'warehouse_id' => Warehouse::default()->id]);
        $order->refreshTotal();
        $docs->created($order);

        $category = AssetCategory::query()->where('name', 'Equipment')->firstOrFail();
        $asset = FixedAsset::query()->create(['number' => 'FA-00001', 'name' => 'Press', 'trans_date' => '2026-10-10', 'usage_date' => '2026-10-10', 'depreciation_method' => DepreciationMethod::StraightLine, 'asset_account_id' => $category->asset_account_id, 'accumulated_depreciation_account_id' => $category->accumulated_depreciation_account_id, 'depreciation_expense_account_id' => $category->depreciation_expense_account_id, 'quantity' => 1, 'useful_life_months' => 12, 'salvage_value' => 0, 'asset_category_id' => $category->id, 'created_by' => auth()->id()]);
        $asset->expenditures()->create(['sort' => 0, 'account_id' => $acc('1102'), 'amount' => 12_000_000]);
        $asset->refreshTotal();
        $docs->created($asset);
        app(DepreciationRun::class)->upTo('2026-11-30');
    }

    private function row(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }
        $this->fail("row {$id} missing");
    }

    public function test_the_balance_sheet_balances_and_the_income_statement_carries_the_period(): void
    {
        $november = new Period('2026-11-01', '2026-11-30');
        $sheet = FinancialStatements::balanceSheet($november);
        $assets = $this->row($sheet, 't-assets')['amount'];
        $this->assertSame($assets, $this->row($sheet, 't-liabilities-equity')['amount'], 'assets = liabilities + equity');
        $this->assertSame(38_000_000 + 1_665_000 + 1_000_000, $this->row($sheet, 't-current_assets')['amount'], 'cash after the asset, the receivable and the stock left');
        $this->assertSame(12_000_000 - 2_000_000, $this->row($sheet, 't-non_current_assets')['amount'], 'the asset net of two months of depreciation');

        $pl = FinancialStatements::incomeStatement($november);
        $this->assertSame(1_500_000, $this->row($pl, 't-revenue')['amount']);
        $this->assertSame(1_000_000, $this->row($pl, 't-cost_of_sales')['amount']);
        $this->assertSame(500_000, $this->row($pl, 't-gross')['amount']);
        $this->assertSame(1_000_000, $this->row($pl, 't-expenses')['amount'], 'November depreciation');
        $this->assertSame(-500_000, $this->row($pl, 't-net')['amount']);
        $this->assertSame(500_000, $this->row($sheet, 'net-income')['amount'], 'to date: the opening stock adjustment (2.000.000 against inventory adjustments) less two months of depreciation and the sale margin');

        $october = new Period('2026-10-01', '2026-10-31');
        $this->assertSame(0, $this->row(FinancialStatements::incomeStatement($october), 't-revenue')['amount']);

        $trial = FinancialStatements::trialBalance($november);
        $total = $this->row($trial, 'total');
        $this->assertSame($total['debit'], $total['credit'], 'the period balances');
        $this->assertSame(0, $total['opening'], 'opening debits equal opening credits');
        $this->assertSame(0, $total['closing']);

        $equity = FinancialStatements::equityChanges($november);
        $this->assertSame(50_000_000 + 1_000_000, $this->row($equity, 'opening')['amount'], 'capital plus October: the stock adjustment less a month of depreciation');
        $this->assertSame(-500_000, $this->row($equity, 'income')['amount']);
        $this->assertSame(50_500_000, $this->row($equity, 'closing')['amount']);

        $cash = FinancialStatements::cashFlow($october);
        $this->assertSame(50_000_000, $this->row($cash, 't-financing')['amount']);
        $this->assertSame(-12_000_000, $this->row($cash, 't-investing')['amount']);
        $this->assertSame(38_000_000, $this->row($cash, 'closing-cash')['amount']);
    }

    public function test_trade_inventory_cash_and_asset_reports_read_the_same_ledgers(): void
    {
        $november = new Period('2026-11-01', '2026-11-30');

        $byCustomer = TradeReports::salesBy('party', $november);
        $this->assertSame('Acme Trading', $byCustomer[0]['name']);
        $this->assertSame(1_500_000, $byCustomer[0]['amount']);
        $this->assertSame(165_000, $byCustomer[0]['tax']);
        $this->assertSame('10.0000', $byCustomer[0]['quantity']);
        $this->assertSame(1_665_000, $this->row($byCustomer, 'total')['total']);
        $this->assertSame('ITM-00001 · Widget', TradeReports::salesBy('item', $november)[0]['name']);
        $this->assertSame('(no salesperson)', TradeReports::salesBy('salesman', $november)[0]['name']);

        $open = TradeReports::openSalesOrders($november);
        $this->assertSame('SO-1', $open[0]['number']);
        $this->assertSame('4.0000', $open[0]['remaining']);
        $this->assertSame(600_000, $this->row($open, 'total')['value']);

        $aging = TradeReports::receivableAging(new Period('2026-11-01', '2026-12-20'));
        $this->assertSame(1_665_000, $aging[0]['31_60'], '45 days after the invoice');
        $this->assertSame(1_665_000, $this->row($aging, 'total')['total']);
        $this->assertSame([], array_slice(TradeReports::payableAging($november), 0, -1), 'nothing owed');

        $card = InventoryReports::stockCard($this->item->id, $november);
        $this->assertSame('20.0000', $card[0]['balance_qty'], 'opening from October');
        $this->assertSame('10.0000', $card[1]['out']);
        $this->assertSame('10.0000', $card[1]['balance_qty']);
        $this->assertSame(1_000_000, $card[1]['balance_value']);
        $value = InventoryReports::inventoryValue();
        $this->assertSame(1_000_000, $this->row($value, 'total')['value']);

        $bank = CashAndAssetReports::bankMutations($november);
        $this->assertSame(38_000_000, $bank[0]['opening']);
        $this->assertSame(38_000_000, $this->row($bank, 'total')['closing']);

        $schedule = CashAndAssetReports::depreciationSchedule($november);
        $this->assertSame(12_000_000, $schedule[0]['cost']);
        $this->assertSame(1_000_000, $schedule[0]['period']);
        $this->assertSame(2_000_000, $schedule[0]['accumulated']);
        $this->assertSame(10_000_000, $schedule[0]['book_value']);
    }
}
