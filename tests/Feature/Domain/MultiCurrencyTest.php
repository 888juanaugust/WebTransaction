<?php

namespace Tests\Feature\Domain;

use App\Domain\Currency\Currencies;
use App\Domain\Currency\CurrencyRates;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Models\Company\Currency;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReceipt;
use App\Models\Sales\SalesReturn;
use App\Models\Settlement\PaymentAllocation;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/** Documents in a foreign currency: base amounts at the document's rate, VAT in rupiah, the exchange difference realised on settlement. */
class MultiCurrencyTest extends TestCase
{
    private Currency $usd;

    private Customer $customer;

    private Vendor $vendor;

    private Item $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $this->usd = Currency::query()->create(['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'decimals' => 2, 'is_active' => true]);
        $this->usd->rates()->createMany([['valid_from' => '2026-11-01', 'rate' => '15500', 'tax_rate' => '15600'], ['valid_from' => '2026-11-15', 'rate' => '15800']]);
        $this->customer = $this->sampleCustomer(['currency_id' => $this->usd->id]);
        $this->vendor = $this->sampleVendor(['currency_id' => $this->usd->id]);
        $this->service = $this->sampleItem(['number' => 'SVC-1', 'name' => 'Consulting', 'item_type' => 'service']);
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[(int) Account::query()->where('no', $no)->value('id')] ?? 0;
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    private function invoice(bool $taxable = false, string $number = 'INV-1'): SalesInvoice
    {
        $invoice = SalesInvoice::query()->create(['number' => $number, 'trans_date' => '2026-11-10', 'customer_id' => $this->customer->id, 'taxable' => $taxable, 'inclusive_tax' => false,
            'currency_id' => $this->usd->id, 'exchange_rate' => '15500', 'tax_exchange_rate' => '15600', 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->service->id, 'quantity' => 4, 'unit_id' => $this->service->unit1_id, 'base_quantity' => 4, 'fc_unit_price' => '250.00', 'unit_price' => 0,
            'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);

        return $invoice->fresh();
    }

    private function receipt(SalesInvoice $invoice, int $fcAmount, string $rate, ?int $bank = null, string $number = 'RC-1'): SalesReceipt
    {
        $receipt = SalesReceipt::query()->create(['number' => $number, 'trans_date' => '2026-11-16', 'customer_id' => $this->customer->id, 'bank_account_id' => $bank ?? $this->account('1102'),
            'currency_id' => $this->usd->id, 'exchange_rate' => $rate, 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'sales_invoice', 'receivable_id' => $invoice->id, 'fc_amount' => $fcAmount, 'fc_discount' => 0, 'amount' => 0, 'discount' => 0]);
        $receipt->refreshTotal();
        app(DocumentRepository::class)->created($receipt);

        return $receipt->fresh();
    }

    public function test_the_switch_needs_a_foreign_currency_and_rates_fall_back(): void
    {
        $this->assertTrue(Currencies::enabled());
        $this->usd->update(['is_active' => false]);
        $this->assertFalse(Currencies::enabled(), 'with only the base currency active nothing changes');
        $this->assertSame(['rate' => '15500.00000000', 'tax_rate' => '15600.00000000'], CurrencyRates::on($this->usd->id, '2026-11-14'));
        $this->assertSame('15800.00000000', CurrencyRates::on($this->usd->id, '2026-11-20')['tax_rate'], 'no tax rate: the book rate');
        $this->assertNull(CurrencyRates::on($this->usd->id, '2026-10-31'));
    }

    public function test_a_usd_invoice_settled_at_a_higher_rate_posts_the_gain(): void
    {
        $invoice = $this->invoice();
        $this->assertSame(100_000, $invoice->fc_total, 'USD 1,000.00 in cents');
        $this->assertSame(15_500_000, $invoice->total);
        $this->assertSame(15_500_000, $this->balance('1200'));
        $this->assertSame(15_500_000, $this->balance('4100'));

        $this->receipt($invoice, 100_000, '15800');
        $invoice->refresh();
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame(100_000, $invoice->fc_paid_amount);
        $this->assertSame(0, $this->balance('1200'), 'the receivable leaves at the value it came in at');
        $this->assertSame(15_800_000, $this->balance('1102'));
        $this->assertSame(300_000, $this->balance('7300'), 'realised exchange gain');
        $this->assertSame(300_000, (int) PaymentAllocation::query()->sole()->fx_difference);
    }

    public function test_part_payments_leave_nothing_behind_and_a_purchase_books_the_loss(): void
    {
        $invoice = SalesInvoice::query()->create(['number' => 'INV-3', 'trans_date' => '2026-11-10', 'customer_id' => $this->customer->id, 'taxable' => false, 'inclusive_tax' => false, 'currency_id' => $this->usd->id, 'exchange_rate' => '15500', 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->service->id, 'quantity' => 1, 'unit_id' => $this->service->unit1_id, 'base_quantity' => 1, 'fc_unit_price' => '999.99', 'unit_price' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);
        foreach ([33_333, 33_333, 33_333] as $i => $part) {
            $this->receipt($invoice->fresh(), $part, '15500', null, 'RC-'.$i);
        }
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(0, $this->balance('1200'), 'three thirds clear it to the rupiah');
        $this->assertSame(0, $this->balance('7300') + $this->balance('8300'));

        $bill = PurchaseInvoice::query()->create(['number' => 'BILL-1', 'trans_date' => '2026-11-10', 'vendor_id' => $this->vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'currency_id' => $this->usd->id, 'exchange_rate' => '15500', 'created_by' => auth()->id()]);
        $bill->lines()->create(['sort' => 0, 'item_id' => $this->service->id, 'quantity' => 1, 'unit_id' => $this->service->unit1_id, 'base_quantity' => 1, 'fc_unit_price' => '1000.00', 'unit_price' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $bill->refreshTotal();
        app(DocumentRepository::class)->created($bill);
        $payment = PurchasePayment::query()->create(['number' => 'PY-1', 'trans_date' => '2026-11-16', 'vendor_id' => $this->vendor->id, 'bank_account_id' => $this->account('1102'), 'payment_method' => 'bank_transfer', 'currency_id' => $this->usd->id, 'exchange_rate' => '15800', 'created_by' => auth()->id()]);
        $payment->lines()->create(['sort' => 0, 'payable_type' => 'purchase_invoice', 'payable_id' => $bill->id, 'fc_amount' => 100_000, 'fc_discount' => 0, 'amount' => 0, 'discount' => 0]);
        $payment->refreshTotal();
        app(DocumentRepository::class)->created($payment);
        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertSame(0, $this->balance('2100'));
        $this->assertSame(300_000, $this->balance('8300'), 'realised exchange loss');
    }

    public function test_vat_is_in_rupiah_at_the_tax_rate_and_deleting_the_receipt_reopens_the_invoice(): void
    {
        $invoice = $this->invoice(true);
        $this->assertSame(14_300_000, $invoice->dpp_total, '11/12 of USD 1,000.00 at the tax rate of 15,600');
        $this->assertSame(1_716_000, $invoice->tax_total);
        $this->assertSame(17_216_000, $invoice->total, '15,500,000 plus VAT in rupiah');
        $this->assertSame(111_000, $invoice->fc_total, 'USD 1,110.00 including VAT');

        $receipt = $this->receipt($invoice, 111_000, '15800');
        $this->assertSame(17_538_000 - 17_216_000, $this->balance('7300'));
        app(DocumentRepository::class)->delete($receipt);
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $this->assertSame(0, $this->balance('7300'));
        $this->assertSame(17_216_000, $this->balance('1200'));
    }

    public function test_a_foreign_bank_holds_dollars_and_pays_out_at_its_own_rate(): void
    {
        $bankUsd = Account::query()->create(['no' => '1150', 'name' => 'Bank USD', 'account_type' => 'cash_bank', 'currency_id' => $this->usd->id, 'is_active' => true, 'used_all_user' => true]);
        $this->receipt($this->invoice(), 100_000, '15800', $bankUsd->id);
        $line = JournalLine::query()->active()->where('account_id', $bankUsd->id)->sole();
        $this->assertSame([15_800_000, 100_000], [(int) $line->debit, (int) $line->fc_amount]);

        $bill = PurchaseInvoice::query()->create(['number' => 'BILL-2', 'trans_date' => '2026-11-16', 'vendor_id' => $this->vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'currency_id' => $this->usd->id, 'exchange_rate' => '16000', 'created_by' => auth()->id()]);
        $bill->lines()->create(['sort' => 0, 'item_id' => $this->service->id, 'quantity' => 1, 'unit_id' => $this->service->unit1_id, 'base_quantity' => 1, 'fc_unit_price' => '1000.00', 'unit_price' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $bill->refreshTotal();
        app(DocumentRepository::class)->created($bill);
        $payment = PurchasePayment::query()->create(['number' => 'PY-2', 'trans_date' => '2026-11-16', 'vendor_id' => $this->vendor->id, 'bank_account_id' => $bankUsd->id, 'payment_method' => 'bank_transfer', 'currency_id' => $this->usd->id, 'exchange_rate' => '16000', 'created_by' => auth()->id()]);
        $payment->lines()->create(['sort' => 0, 'payable_type' => 'purchase_invoice', 'payable_id' => $bill->id, 'fc_amount' => 100_000, 'fc_discount' => 0, 'amount' => 0, 'discount' => 0]);
        $payment->refreshTotal();
        app(DocumentRepository::class)->created($payment);

        $this->assertSame(0, $this->balance('1150'), 'the emptied dollar account leaves no rupiah behind');
        $this->assertSame(0, (int) JournalLine::query()->active()->where('account_id', $bankUsd->id)->sum('fc_amount'));
        $this->assertSame(300_000 + 200_000, $this->balance('7300') - $this->balance('8300') + 0, 'gain on the receipt, gain on paying the bill below its value');
    }

    public function test_a_return_settles_as_a_credit_and_a_settled_invoice_is_locked(): void
    {
        $invoice = $this->invoice();
        $return = SalesReturn::query()->create(['number' => 'SR-1', 'trans_date' => '2026-11-12', 'customer_id' => $this->customer->id, 'taxable' => false, 'inclusive_tax' => false,
            'currency_id' => $this->usd->id, 'exchange_rate' => '15500', 'created_by' => auth()->id()]);
        $return->lines()->create(['sort' => 0, 'item_id' => $this->service->id, 'quantity' => 1, 'unit_id' => $this->service->unit1_id, 'base_quantity' => 1, 'fc_unit_price' => '200.00', 'unit_price' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $return->refreshTotal();
        app(DocumentRepository::class)->created($return);
        $this->assertSame([20_000, 3_100_000], [(int) $return->fresh()->fc_total, (int) $return->fresh()->total]);

        // USD 800.00 received at 15,800 for the invoice less the credit.
        $receipt = SalesReceipt::query()->create(['number' => 'RC-1', 'trans_date' => '2026-11-16', 'customer_id' => $this->customer->id, 'bank_account_id' => $this->account('1102'),
            'currency_id' => $this->usd->id, 'exchange_rate' => '15800', 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'sales_invoice', 'receivable_id' => $invoice->id, 'fc_amount' => 100_000, 'fc_discount' => 0, 'amount' => 0, 'discount' => 0]);
        $receipt->lines()->create(['sort' => 1, 'receivable_type' => 'sales_return', 'receivable_id' => $return->id, 'fc_amount' => -20_000, 'fc_discount' => 0, 'amount' => 0, 'discount' => 0]);
        $receipt->refreshTotal();
        app(DocumentRepository::class)->created($receipt);

        $this->assertSame(12_640_000, (int) $receipt->fresh()->amount);
        $this->assertSame(0, $this->balance('1200'), 'the invoice and the credit leave at their booked values');
        $this->assertSame(300_000 - 60_000, $this->balance('7300'), 'gain on the invoice, less the dearer credit');
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame('paid', $return->fresh()->payment_status);

        try {
            app(DocumentRepository::class)->beforeUpdate($invoice->fresh());
            $this->fail('a settled invoice is locked');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payments have been applied', $e->getMessage());
        }
    }

    public function test_currencies_must_match(): void
    {
        $invoice = $this->invoice();
        $receipt = SalesReceipt::query()->create(['number' => 'RC-9', 'trans_date' => '2026-11-16', 'customer_id' => $this->customer->id, 'bank_account_id' => $this->account('1102'), 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'sales_invoice', 'receivable_id' => $invoice->id, 'amount' => 15_500_000, 'discount' => 0]);
        $receipt->refreshTotal();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INV-1 is in USD; this payment is in IDR.');
        app(DocumentRepository::class)->created($receipt);
    }
}
