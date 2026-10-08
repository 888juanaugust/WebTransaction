<?php

namespace Tests\Feature\Domain;

use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReturn;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** "Discount on the total" is spread over the lines: revenue, cost and the party account all take the discounted amounts. */
class HeaderDiscountTest extends TestCase
{
    private Item $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $this->service = $this->sampleItem(['number' => 'SVC-1', 'name' => 'Consulting', 'item_type' => 'service']);
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[(int) Account::query()->where('no', $no)->value('id')] ?? 0;
    }

    private function lines(): array
    {
        $line = ['item_id' => $this->service->id, 'unit_id' => $this->service->unit1_id, 'warehouse_id' => Warehouse::default()->id, 'tax_code_id' => TaxCode::default()->id];

        return [
            $line + ['sort' => 0, 'quantity' => 1, 'base_quantity' => 1, 'unit_price' => 1_000_000],
            $line + ['sort' => 1, 'quantity' => 3, 'base_quantity' => 3, 'unit_price' => 500_000],
        ];
    }

    public function test_a_sales_invoice_with_a_discount_on_the_total_posts_balanced_at_the_discounted_amounts(): void
    {
        $invoice = SalesInvoice::query()->create(['number' => 'INV-1', 'trans_date' => '2026-11-10', 'customer_id' => $this->sampleCustomer()->id, 'taxable' => true, 'inclusive_tax' => false, 'discount_percent' => 10, 'created_by' => auth()->id()]);
        $invoice->lines()->createMany($this->lines());
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);
        $invoice->refresh();

        $this->assertSame(2_500_000, $invoice->subtotal);
        $this->assertSame(250_000, $invoice->discount_amount);
        $this->assertSame(247_500, $invoice->tax_total, '12 % on 11/12 of 2,250,000');
        $this->assertSame(2_497_500, $invoice->total);
        $this->assertSame([100_000, 150_000], $invoice->lines->pluck('header_discount')->map(fn ($v) => (int) $v)->all(), 'spread in proportion');
        $this->assertSame(2_497_500, $this->balance('1200'));
        $this->assertSame(2_250_000, $this->balance('4100'), 'revenue net of the discount');

        $invoice->forceFill(['discount_percent' => 20])->save();
        $invoice->refreshTotal();
        $this->assertSame(500_000, $invoice->fresh()->discount_amount, 'a new percentage is applied, not the old amount');
    }

    public function test_tax_inclusive_prices_returns_and_purchases_balance_too(): void
    {
        $invoice = SalesInvoice::query()->create(['number' => 'INV-2', 'trans_date' => '2026-11-10', 'customer_id' => $this->sampleCustomer()->id, 'taxable' => true, 'inclusive_tax' => true, 'discount_percent' => 10, 'created_by' => auth()->id()]);
        $invoice->lines()->createMany($this->lines());
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);
        $this->assertSame(2_250_000, $invoice->fresh()->total);
        $this->assertSame(2_250_000, $this->balance('1200'));

        $return = SalesReturn::query()->create(['number' => 'SR-1', 'trans_date' => '2026-11-12', 'customer_id' => $invoice->customer_id, 'taxable' => true, 'inclusive_tax' => false, 'discount_percent' => 10, 'created_by' => auth()->id()]);
        $return->lines()->createMany([$this->lines()[0]]);
        $return->refreshTotal();
        app(DocumentRepository::class)->created($return);
        $this->assertSame(999_000, $return->fresh()->total, '900,000 plus 99,000 VAT');

        $bill = PurchaseInvoice::query()->create(['number' => 'BILL-1', 'trans_date' => '2026-11-10', 'vendor_id' => $this->sampleVendor()->id, 'taxable' => false, 'inclusive_tax' => false, 'discount_percent' => 10, 'created_by' => auth()->id()]);
        $bill->lines()->createMany(array_map(fn (array $l) => array_diff_key($l, ['tax_code_id' => 0]), $this->lines()));
        $bill->refreshTotal();
        app(DocumentRepository::class)->created($bill);
        $this->assertSame(2_250_000, $bill->fresh()->total);
    }

    public function test_a_changed_line_percent_reprices_the_line(): void
    {
        $invoice = SalesInvoice::query()->create(['number' => 'INV-9', 'trans_date' => '2026-11-10', 'customer_id' => $this->sampleCustomer()->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $line = $invoice->lines()->create($this->lines()[0] + ['discount_percent' => 10]);
        $invoice->refreshTotal();
        $this->assertSame(100_000, (int) $line->fresh()->discount_amount);

        // The form sends the stored amount back with the new quantity and percent: the percent decides.
        $line->update(['quantity' => 2, 'base_quantity' => 2, 'discount_percent' => 5, 'discount_amount' => 100_000]);
        $invoice->refreshTotal();
        $this->assertSame(100_000, (int) $line->fresh()->discount_amount, '5 % of 2,000,000');
        $line->update(['discount_percent' => 20]);
        $invoice->refreshTotal();
        $this->assertSame(400_000, (int) $line->fresh()->discount_amount);
        $this->assertSame(1_600_000, $invoice->fresh()->total);

        // Without a percent, a fixed amount stands.
        $line->update(['discount_percent' => 0, 'discount_amount' => 50_000]);
        $invoice->refreshTotal();
        $this->assertSame(1_950_000, $invoice->fresh()->total);
    }
}
