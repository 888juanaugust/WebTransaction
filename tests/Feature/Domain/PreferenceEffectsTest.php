<?php

namespace Tests\Feature\Domain;

use App\Domain\Company\CompanyIdentity;
use App\Domain\Company\DataStart;
use App\Domain\Company\FiscalYear;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Pengaturan\PreferensiTab;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\Exceptions\PeriodClosedException;
use App\Domain\Reports\AgingBuckets;
use App\Domain\Reports\FinancialStatements;
use App\Domain\Reports\Period;
use App\Domain\Reports\TradeReports;
use App\Filament\Pages\Reports\VatReturn;
use App\Filament\Pages\Settings\Preferences;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalVoucher;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** Each preference this release wires changes what the system does; the ones it does not use are not offered. */
class PreferenceEffectsTest extends TestCase
{
    private Preferensi $prefs;

    private DocumentRepository $docs;

    private Customer $customer;

    private Vendor $vendor;

    private Item $item;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $this->prefs = app(Preferensi::class);
        $this->docs = app(DocumentRepository::class);
        $this->customer = $this->sampleCustomer();
        $this->vendor = $this->sampleVendor();
        $this->item = $this->sampleItem()->fresh();
        $this->warehouse = Warehouse::default();
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    private function balance(string $no, ?string $asOf = null): int
    {
        return AccountBalances::asOf($asOf)[$this->account($no)] ?? 0;
    }

    private function stock(int $qty, int $cost, string $date = '2026-10-01'): void
    {
        $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-'.uniqid(), 'trans_date' => $date, 'created_by' => auth()->id()]);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_cost' => $cost, 'total_cost' => 0, 'warehouse_id' => $this->warehouse->id]);
        $this->docs->created($adjustment);
    }

    private function invoice(int $qty, string $date = '2026-11-05'): SalesInvoice
    {
        $invoice = SalesInvoice::query()->create(['number' => 'INV-'.uniqid(), 'trans_date' => $date, 'customer_id' => $this->customer->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_price' => 150_000, 'warehouse_id' => $this->warehouse->id]);
        $invoice->refreshTotal();
        $this->docs->created($invoice);

        return $invoice->fresh();
    }

    private function salesReturn(SalesInvoice $invoice, int $qty): SalesReturn
    {
        $return = SalesReturn::query()->create(['number' => 'SR-'.uniqid(), 'trans_date' => '2026-11-10', 'customer_id' => $this->customer->id, 'return_type' => 'invoice', 'source_type' => 'sales_invoice', 'source_id' => $invoice->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $return->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_price' => 150_000, 'warehouse_id' => $this->warehouse->id]);
        $return->refreshTotal();
        $this->docs->created($return);

        return $return->fresh();
    }

    private function bill(int $price, string $date, int $qty = 1): PurchaseInvoice
    {
        $bill = PurchaseInvoice::query()->create(['number' => 'BILL-'.uniqid(), 'trans_date' => $date, 'vendor_id' => $this->vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $bill->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_price' => $price, 'discount_percent' => 10, 'warehouse_id' => $this->warehouse->id]);
        $bill->refreshTotal();
        $this->docs->created($bill);

        return $bill->fresh();
    }

    public function test_the_letterhead_carries_the_fax_and_the_vat_return_names_the_vat_registration(): void
    {
        $this->prefs->setMany([
            PreferensiKey::CompanyName->value => 'Example Co',
            PreferensiKey::CompanyFax->value => '021-555-0101',
            PreferensiKey::CompanyNpwp->value => '01.234.567.8-901.000',
            PreferensiKey::PkpNumber->value => 'PEM-00123/WPJ.07/2020',
            PreferensiKey::PkpDate->value => '2020-03-01',
            PreferensiKey::BusinessType->value => 'Wholesale trade',
            PreferensiKey::Klu->value => '46599',
        ]);

        $this->assertSame('021-555-0101', app(CompanyIdentity::class)->letterhead()['fax']);
        $this->stock(5, 100_000);
        $invoice = $this->invoice(1);
        $this->get(URL::signedRoute('filament.admin.print', ['alias' => 'sales_invoice', 'id' => $invoice->id]))->assertOk()->assertSee('Fax 021-555-0101');

        Livewire::test(VatReturn::class)
            ->assertSee('PEM-00123/WPJ.07/2020')
            ->assertSee('since 1 Mar 2020')
            ->assertSee('Wholesale trade')
            ->assertSee('KLU 46599');
    }

    public function test_nothing_is_dated_before_the_data_start_date_and_opening_balances_start_on_it(): void
    {
        $this->prefs->set(PreferensiKey::DataStartDate, '2026-01-01');
        $this->assertSame('2026-01-01', DataStart::openingDate());

        $voucher = JournalVoucher::query()->create(['number' => 'JV-OLD', 'trans_date' => '2025-12-31', 'created_by' => auth()->id()]);
        $voucher->lines()->createMany([
            ['sort' => 0, 'account_id' => $this->account('6100'), 'debit' => 1_000, 'credit' => 0],
            ['sort' => 1, 'account_id' => $this->account('1101'), 'debit' => 0, 'credit' => 1_000],
        ]);
        $this->expectException(PeriodClosedException::class);
        $this->expectExceptionMessage('before the data start date, 1 Jan 2026');
        $this->docs->created($voucher);
    }

    public function test_the_fiscal_year_splits_retained_earnings_from_this_years_income(): void
    {
        $this->prefs->set(PreferensiKey::FiscalYearStartMonth, '4');
        $this->assertSame('2026-04-01', FiscalYear::startOf('2026-11-16')->toDateString());
        $this->assertSame('2025-04-01', FiscalYear::startOf('2026-02-10')->toDateString());
        $this->assertSame('2027-03-31', FiscalYear::endOf('2026-11-16')->toDateString());

        $this->item = $this->sampleItem(['number' => 'SVC-00001', 'name' => 'Fitting', 'item_type' => 'service'])->fresh();
        $this->invoice(1, '2026-02-15'); // last fiscal year: 150,000 revenue
        $this->invoice(2, '2026-06-15'); // this fiscal year: 300,000

        $rows = collect(FinancialStatements::balanceSheet(new Period('2026-11-01', '2026-11-16')))->keyBy('id');
        $this->assertSame(150_000, $rows['retained-earnings']['amount']);
        $this->assertSame(300_000, $rows['net-income']['amount']);
        $this->assertSame('Net income this year', $rows['net-income']['name']);
    }

    public function test_a_return_comes_back_at_the_cost_it_left_with_or_the_last_purchase_price_and_to_the_chosen_account(): void
    {
        $this->stock(10, 100_000);
        $invoice = $this->invoice(4);

        $this->assertSame('sales_invoice_cogs', $this->prefs->get(PreferensiKey::CogsSource));
        $this->salesReturn($invoice, 1);
        $this->assertSame(400_000 - 100_000, $this->balance('5100'), 'the cost it left with');

        $this->prefs->set(PreferensiKey::CogsSource, 'last_purchase_cost');
        $this->item->update(['purchase_price' => 90_000]);
        $this->prefs->set(PreferensiKey::ReturnCostCharge, 'account');
        $this->prefs->set(PreferensiKey::ReturnCostAccount, $this->account('5200'));
        $before = $this->balance('5200');
        $return = $this->salesReturn($invoice, 1);
        $this->assertSame($before - 90_000, $this->balance('5200'), 'at the last purchase price, credited to the chosen account');
        $this->assertSame(300_000, $this->balance('5100'), 'cost of sales untouched');

        // With "update cost on re-save" off, saving the return again keeps the 90,000.
        $this->prefs->set(PreferensiKey::UpdateCostOnReturnResave, false);
        $this->item->update(['purchase_price' => 120_000]);
        $before = $this->docs->beforeUpdate($return);
        $this->docs->updated($return, $before);
        $this->assertSame('90000.0000', (string) StockMovement::query()->active()->where('source_line_type', 'sales_return_line')->where('source_line_id', $return->lines()->first()->id)->value('unit_cost'));

        $this->prefs->set(PreferensiKey::UpdateCostOnReturnResave, true);
        $this->docs->updated($return, $this->docs->beforeUpdate($return));
        $this->assertSame('120000.0000', (string) StockMovement::query()->active()->where('source_line_type', 'sales_return_line')->where('source_line_id', $return->lines()->first()->id)->value('unit_cost'));
    }

    public function test_purchase_invoices_keep_the_last_purchase_price_from_the_cutoff_date(): void
    {
        $this->assertTrue($this->prefs->get(PreferensiKey::LastPriceUpdatedByBill));
        $this->bill(110_000, '2026-11-02');
        $this->assertSame(99_000, (int) $this->item->fresh()->purchase_price, 'net of the 10 % discount, per base unit');

        $this->bill(150_000, '2026-10-01');
        $this->assertSame(99_000, (int) $this->item->fresh()->purchase_price, 'an older invoice changes nothing');

        $latest = $this->bill(120_000, '2026-11-10', 2);
        $this->assertSame(108_000, (int) $this->item->fresh()->purchase_price);
        $this->docs->delete($latest);
        $this->assertSame(99_000, (int) $this->item->fresh()->purchase_price, 'deleting the latest falls back to the one before');

        $this->prefs->set(PreferensiKey::LastPriceCutoffDate, '2026-11-15');
        $this->bill(130_000, '2026-11-12');
        $this->assertSame(99_000, (int) $this->item->fresh()->purchase_price, 'before the cutoff');

        $this->prefs->set(PreferensiKey::LastPriceUpdatedByBill, false);
        $this->bill(140_000, '2026-11-16');
        $this->assertSame(99_000, (int) $this->item->fresh()->purchase_price, 'switched off');
    }

    public function test_aging_buckets_and_basis_follow_preferences(): void
    {
        $this->assertSame(['current', '1_30', '31_60', '61_90', 'over_90'], array_column(AgingBuckets::all(), 'key'));
        $this->prefs->set(PreferensiKey::AgingIntervalDays, 15);
        $this->prefs->set(PreferensiKey::AgingRangeDays, 45);
        $this->assertSame(['current', '1_15', '16_30', '31_45', 'over_45'], array_column(AgingBuckets::all(), 'key'));

        $this->stock(10, 100_000);
        $invoice = $this->invoice(1, '2026-10-01');
        $invoice->forceFill(['due_date' => '2026-11-10'])->saveQuietly();
        $aging = TradeReports::receivableAging(new Period('2026-11-01', '2026-11-16'));
        $this->assertSame(150_000, $aging[0]['over_45'], '46 days after the invoice date');

        $this->prefs->set(PreferensiKey::AgingBasis, 'due_date');
        $aging = TradeReports::receivableAging(new Period('2026-11-01', '2026-11-16'));
        $this->assertSame(150_000, $aging[0]['1_15'], 'six days after the due date');
    }

    public function test_preferences_nothing_uses_are_not_offered_and_keep_their_stored_values(): void
    {
        $this->prefs->set(PreferensiKey::AttachSalesInvoice, true);
        $this->assertSame([], PreferensiTab::Attachments->offeredKeys());
        $this->assertSame([], PreferensiTab::ExtraAttributes->offeredKeys());
        $this->assertFalse(PreferensiKey::EmployeeLoan->isOffered());

        Livewire::test(Preferences::class)
            ->assertDontSee(PreferensiKey::TemporaryPaymentAccount->label())
            ->assertDontSee(PreferensiTab::Attachments->label())
            ->set('data.'.Preferences::fieldName(PreferensiKey::CompanyName), 'Renamed Co')
            ->call('save');

        $this->assertSame('Renamed Co', app(Preferensi::class)->get(PreferensiKey::CompanyName));
        $this->assertTrue(app(Preferensi::class)->get(PreferensiKey::AttachSalesInvoice), 'a hidden preference keeps its value');
        $this->assertNotNull(TaxCode::default());
    }
}
