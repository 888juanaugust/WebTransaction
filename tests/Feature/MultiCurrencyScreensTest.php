<?php

namespace Tests\Feature;

use App\Domain\Company\OpeningBalances;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Reports\Period;
use App\Domain\Reports\TradeReports;
use App\Filament\Pages\Reports\CustomerStatement;
use App\Filament\Pages\Reports\ReceivableAging;
use App\Filament\Resources\Company\Currencies\Pages\ManageCurrencies;
use App\Filament\Resources\GeneralLedger\Accounts\Pages\ManageAccounts;
use App\Filament\Resources\Purchasing\PurchaseDownPayments\PurchaseDownPaymentResource;
use App\Filament\Resources\Purchasing\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\Purchasing\PurchasePayments\Pages\CreatePurchasePayment;
use App\Filament\Resources\Purchasing\PurchasePayments\PurchasePaymentResource;
use App\Filament\Resources\Purchasing\Vendors\VendorResource;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use App\Filament\Resources\Sales\SalesDownPayments\Pages\CreateSalesDownPayment;
use App\Filament\Resources\Sales\SalesDownPayments\SalesDownPaymentResource;
use App\Filament\Resources\Sales\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Resources\Sales\SalesInvoices\Pages\EditSalesInvoice;
use App\Filament\Resources\Sales\SalesInvoices\SalesInvoiceResource;
use App\Filament\Resources\Sales\SalesReceipts\Pages\CreateSalesReceipt;
use App\Filament\Resources\Sales\SalesReceipts\SalesReceiptResource;
use App\Filament\Support\ReceivableFields;
use App\Models\Company\Currency;
use App\Models\Company\OpeningBalance;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesDownPayment;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReceipt;
use App\Models\Settlement\PaymentAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** The screens of a document in a foreign currency: typed in its currency, kept in fc_* columns, base amounts at its rate. */
class MultiCurrencyScreensTest extends TestCase
{
    private Currency $usd;

    private Customer $customer;

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
        $this->service = $this->sampleItem(['number' => 'SVC-1', 'name' => 'Consulting', 'item_type' => 'service']);
    }

    private function line(string $price, int $quantity = 4): array
    {
        return ['item_id' => $this->service->id, 'quantity' => $quantity, 'unit_id' => $this->service->unit1_id, 'unit_price' => $price, 'discount_percent' => 0,
            'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id];
    }

    private function createInvoice(array $extra = []): SalesInvoice
    {
        Livewire::test(CreateSalesInvoice::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['currency_id' => $this->usd->id, 'exchange_rate' => '15800.00000000'])
            ->fillForm(['taxable' => false, 'lines' => [$this->line('250.00')], ...$extra])
            ->call('create')
            ->assertHasNoFormErrors();

        return SalesInvoice::query()->latest('id')->firstOrFail();
    }

    public function test_an_invoice_is_typed_in_its_currency_and_kept_in_both(): void
    {
        $expense = (int) Account::query()->where('no', '6100')->value('id');
        $invoice = $this->createInvoice(['charges' => [['account_id' => $expense, 'amount' => '10,50']]]);

        $line = $invoice->lines()->first();
        $this->assertSame('250.0000', $line->fc_unit_price, 'the typed price is the dollar price');
        $this->assertSame('3950000.0000', $line->unit_price, 'its rupiah price at 15,800');
        $this->assertSame(100000, (int) $line->fc_amount);
        $this->assertSame(15_800_000, (int) $line->amount);
        $this->assertSame(1050, (int) $invoice->charges()->first()->fc_amount, 'a charge is typed in the currency too');
        $this->assertSame(165_900, (int) $invoice->charges()->first()->amount);
        $this->assertSame(101050, (int) $invoice->fc_total);
        $this->assertSame(15_965_900, (int) $invoice->total);

        // Opened again, the grid reads in dollars.
        Livewire::test(EditSalesInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSet('data.lines.record-'.$line->id.'.unit_price', '250.0000')
            ->assertSet('data.charges.record-'.$invoice->charges()->first()->id.'.amount', '10,50');

        $this->get(URL::signedRoute('filament.admin.print', ['alias' => 'sales_invoice', 'id' => $invoice->id]))
            ->assertOk()
            ->assertSee('USD 1.010,50')
            ->assertSee('Rate 15.800 per USD.');
    }

    public function test_a_receipt_from_the_invoice_settles_in_dollars_and_realises_the_difference(): void
    {
        $invoice = $this->createInvoice();

        Livewire::withQueryParams(['source' => 'sales_invoice:'.$invoice->id])
            ->test(CreateSalesReceipt::class)
            ->assertSchemaStateSet(['currency_id' => $this->usd->id])
            ->set('data.bank_account_id', Account::query()->where('no', '1102')->value('id'))
            ->set('data.exchange_rate', '16000')
            ->call('create')
            ->assertHasNoFormErrors();

        $receipt = SalesReceipt::query()->firstOrFail();
        $this->assertSame(100000, (int) $receipt->lines()->first()->fc_amount, 'the open "1000,00" became cents');
        $this->assertSame(16_000_000, (int) $receipt->amount);
        $this->assertSame(200_000, (int) PaymentAllocation::query()->value('fx_difference'), 'received at 16,000 what was billed at 15,800');
        $this->assertSame('paid', $invoice->fresh()->payment_status);

        // A receipt in rupiah offers no dollar invoice.
        $this->assertSame([], ReceivableFields::openFor($this->customer->id)->all());
    }

    public function test_a_down_payment_is_typed_in_the_currency_and_deducted_in_it(): void
    {
        Livewire::test(CreateSalesDownPayment::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->fillForm(['taxable' => false, 'amount' => '500,00'])
            ->call('create')
            ->assertHasNoFormErrors();
        $dp = SalesDownPayment::query()->firstOrFail();
        $this->assertSame(50000, (int) $dp->fc_amount);
        $this->assertSame(7_900_000, (int) $dp->total);

        $invoice = $this->createInvoice(['downPayments' => [['sales_down_payment_id' => $dp->id, 'amount' => '500,00']]]);
        $this->assertSame(50000, (int) $invoice->fc_down_payment_total);
        $this->assertSame(7_900_000, (int) $invoice->down_payment_total, 'deducted at the down payment\'s own value');
        $this->assertSame('processed', $dp->fresh()->status);
    }

    public function test_an_opening_balance_in_dollars_is_an_open_dollar_item(): void
    {
        app(Preferensi::class)->set(PreferensiKey::DataStartDate, '2026-01-01');
        app(OpeningBalances::class)->sync($this->customer, [
            'new' => ['document_date' => '2025-12-15', 'currency_id' => $this->usd->id, 'exchange_rate' => '15000', 'amount' => '1.000,00'],
        ]);
        $opening = OpeningBalance::query()->firstOrFail();
        $this->assertSame(100000, $opening->fc_amount);
        $this->assertSame(15_000_000, $opening->amount);
        $this->assertArrayHasKey('opening_balance:'.$opening->id, ReceivableFields::openFor($this->customer->id, true, $this->usd->id)->all());
        $this->assertArrayNotHasKey('opening_balance:'.$opening->id, ReceivableFields::openFor($this->customer->id)->all());
    }

    public function test_aging_and_statements_read_one_currency_in_its_own_amounts(): void
    {
        $this->createInvoice();
        $period = new Period('2026-11-01', '2026-11-30');

        $all = TradeReports::receivableAging($period);
        $this->assertSame(15_800_000, end($all)['total'], 'by default every currency, in rupiah');
        $usd = TradeReports::receivableAging($period, null, $this->usd->id);
        $this->assertSame(100000, end($usd)['total'], 'filtered to dollars, in cents');

        $statement = TradeReports::statement('customer', $this->customer->id, $period, $this->usd->id);
        $this->assertSame(100000, end($statement)['balance']);
    }

    public function test_a_bill_and_its_payment_in_dollars(): void
    {
        $vendor = $this->sampleVendor(['currency_id' => $this->usd->id]);
        Livewire::test(CreatePurchaseInvoice::class)
            ->fillForm(['vendor_id' => $vendor->id])
            ->assertSchemaStateSet(['currency_id' => $this->usd->id])
            ->fillForm(['taxable' => false, 'lines' => [$this->line('250.00')]])
            ->call('create')
            ->assertHasNoFormErrors();
        $bill = PurchaseInvoice::query()->firstOrFail();
        $this->assertSame(100000, (int) $bill->fc_total);
        $this->assertSame(15_800_000, (int) $bill->total);

        Livewire::withQueryParams(['source' => 'purchase_invoice:'.$bill->id])
            ->test(CreatePurchasePayment::class)
            ->set('data.bank_account_id', Account::query()->where('no', '1102')->value('id'))
            ->set('data.exchange_rate', '16000')
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(16_000_000, (int) PurchasePayment::query()->firstOrFail()->amount);
        $this->assertSame(-200_000, (int) PaymentAllocation::query()->value('fx_difference'), 'paid at 16,000 what was billed at 15,800: a loss');
        $this->assertSame('paid', $bill->fresh()->payment_status);
    }

    public function test_the_currency_screens_render_and_save_rates(): void
    {
        $urls = [
            VendorResource::getUrl('create'), SalesReceiptResource::getUrl('create'), PurchasePaymentResource::getUrl('create'),
            SalesDownPaymentResource::getUrl('create'), PurchaseDownPaymentResource::getUrl('create'), SalesInvoiceResource::getUrl('create'),
            ReceivableAging::getUrl(), CustomerStatement::getUrl(),
        ];
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        Livewire::test(ManageAccounts::class)->mountTableAction('edit', Account::query()->where('no', '1102')->firstOrFail())->assertHasNoErrors();
        $this->get(CustomerResource::getUrl('edit', ['record' => $this->customer]))->assertOk()->assertSee('USD');

        Livewire::test(ManageCurrencies::class)
            ->callTableAction('edit', $this->usd, data: ['rates' => [['valid_from' => '2026-11-16', 'rate' => '15900', 'tax_rate' => null]]])
            ->assertHasNoTableActionErrors();
        $this->assertSame(['2026-11-16'], $this->usd->rates()->get()->map(fn ($r) => $r->valid_from->toDateString())->all());
    }

    public function test_no_currency_fields_without_a_foreign_currency(): void
    {
        $this->usd->update(['is_active' => false]);

        Livewire::test(CreateSalesInvoice::class)
            ->assertFormFieldIsHidden('currency_id')
            ->assertFormFieldIsHidden('exchange_rate');
    }
}
