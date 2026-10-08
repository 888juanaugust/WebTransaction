<?php

namespace Tests\Feature;

use App\Domain\Sales\SellingPriceGuard;
use App\Domain\Shared\Enums\AccountType;
use App\Filament\Resources\Sales\SalesOrders\Pages\CreateSalesOrder;
use App\Models\Company\Currency;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesOrder;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** The selling price is checked on the server: without the right, a line sells at the price in force and nothing is taken off. */
class SellingPriceGuardTest extends TestCase
{
    private Customer $customer;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $this->customer = $this->sampleCustomer();
        $this->item = $this->sampleItem(); // sells at 150,000
        $sales = User::factory()->create();
        AccessGroup::query()->where('name', 'Sales')->firstOrFail()->users()->attach($sales);
        $this->actingAs($sales);
        $this->freshRequest();
    }

    private function order(array $line, array $header = [])
    {
        $page = Livewire::test(CreateSalesOrder::class)
            ->fillForm(['customer_id' => $this->customer->id, 'trans_date' => '2026-11-16']); // the customer's terms: prices with tax, VAT charged
        foreach ($header as $field => $value) {
            $page->set("data.{$field}", $value); // after the customer, whose pick fills the header's defaults
        }

        return $page->set('data.lines', ['a' => $line + ['item_id' => $this->item->id, 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'unit_price' => 150_000, 'discount_percent' => 0, 'discount_amount' => 0, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]])
            ->call('create');
    }

    public function test_a_salesperson_sells_at_the_price_in_force(): void
    {
        $this->order(['unit_price' => 1])->assertHasErrors('data.lines');
        $this->order(['discount_percent' => 50])->assertHasErrors('data.lines');
        $this->order(['discount_amount' => 500_000])->assertHasErrors('data.lines');
        $this->order([], ['discount_percent' => 10])->assertHasErrors('data.lines');
        $this->assertSame(0, SalesOrder::query()->count(), 'nothing was saved');

        $this->order([])->assertHasNoErrors();
        $this->assertSame(1_500_000, SalesOrder::query()->sole()->total);
    }

    public function test_the_header_keeps_the_customers_terms(): void
    {
        $this->customer->update(['default_sales_disc' => 5]);
        $this->freshRequest();
        $this->order([], ['discount_percent' => 6])->assertHasErrors('data.lines');
        $this->order([], ['inclusive_tax' => ! $this->customer->fresh()->default_inc_tax])->assertHasErrors('data.lines');
        $this->order([], ['taxable' => false])->assertHasErrors('data.lines');
        $charge = ['account_id' => Account::query()->where('account_type', AccountType::Expense)->value('id'), 'amount' => -500_000, 'description' => 'goodwill'];
        $this->order([], ['charges' => ['c' => $charge]])->assertHasErrors('data.lines');
        $this->assertSame(0, SalesOrder::query()->count());

        // The customer's own default discount on the total is the one in force: once, on the total (not on each line too).
        $this->order([])->assertHasNoErrors();
        $order = SalesOrder::query()->with('lines')->sole();
        $this->assertSame('5.0000', (string) $order->discount_percent);
        $this->assertSame('0.0000', (string) $order->lines->sole()->discount_percent);
        $this->assertSame(1_425_000, $order->total, '1,500,000 less 5 %, prices with tax');
    }

    public function test_a_foreign_document_takes_the_book_rate(): void
    {
        $usd = Currency::query()->create(['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'decimals' => 2, 'is_active' => true]);
        $usd->rates()->create(['valid_from' => '2026-11-01', 'rate' => '15800']);
        $order = fn (string $rate) => SalesOrder::query()->create(['number' => 'SO-'.$rate, 'trans_date' => '2026-11-16', 'customer_id' => $this->customer->id, 'currency_id' => $usd->id, 'exchange_rate' => $rate,
            'taxable' => true, 'inclusive_tax' => (bool) $this->customer->fresh()->default_inc_tax, 'created_by' => auth()->id()]);

        // A higher rate bills fewer dollars for the same rupiah price.
        $this->assertThrows(fn () => app(SellingPriceGuard::class)->check($order('20000')), ValidationException::class, 'book rate');
        app(SellingPriceGuard::class)->check($order('15800'));
    }

    public function test_with_the_right_a_price_may_change(): void
    {
        $this->actingAsAdmin();
        $this->freshRequest();
        $this->order(['unit_price' => 140_000])->assertHasNoErrors();
        $this->assertSame(1_400_000, SalesOrder::query()->sole()->total);
    }
}
