<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Portal\Resources\Orders\Pages\ListOrders;
use App\Filament\Portal\Resources\Tagihan\Pages\ListTagihan;
use App\Filament\Portal\Widgets\OrderTerakhir;
use App\Filament\Portal\Widgets\TagihanTerbuka;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Order;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How long until a bill is due, in words the customer reads.
 *
 * Carbon's diffInDays is both signed and a float, so the obvious expression
 * renders "Jatuh tempo dalam -8 hari" for a bill due next week and "Lewat
 * 45.811393477072 hari" for a late one. Both went in front of a buyer before
 * this test existed, and both are about money owed — exactly the number a
 * customer will quote back at you.
 */
class PortalInvoiceLabelTest extends TestCase
{
    use RefreshDatabase;

    private CustomerUser $buyer;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('portal');

        $this->company = Company::factory()->create();
        $this->buyer = CustomerUser::factory()->create(['company_id' => $this->company->id]);
    }

    private function invoiceDue(string $dueDate, string $status = Invoice::STATUS_OPEN): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $this->company->id,
            'due_date' => $dueDate,
            'issued_on' => now()->subMonth()->toDateString(),
            'status' => $status,
        ]);
    }

    private function renderedTable(): string
    {
        $this->actingAs($this->buyer, 'customer');

        return Livewire::test(ListTagihan::class)->html();
    }

    public function test_a_future_due_date_is_not_reported_as_negative_days(): void
    {
        $this->invoiceDue(now()->addDays(8)->toDateString());

        $html = $this->renderedTable();

        $this->assertStringContainsString('Jatuh tempo dalam 8 hari', $html);
        $this->assertStringNotContainsString('-8 hari', $html);
    }

    public function test_an_overdue_invoice_counts_up_in_whole_days(): void
    {
        $this->invoiceDue(now()->subDays(45)->toDateString());

        $html = $this->renderedTable();

        $this->assertStringContainsString('Lewat 45 hari', $html);
    }

    /** No fractional days anywhere — a day count is a whole number. */
    public function test_no_due_label_ever_shows_a_fraction(): void
    {
        $this->invoiceDue(now()->addDays(3)->toDateString());
        $this->invoiceDue(now()->subDays(3)->toDateString());

        $this->assertDoesNotMatchRegularExpression(
            '/\d+\.\d+ hari/',
            $this->renderedTable(),
        );
    }

    /**
     * The dashboard widget and the Tagihan page list the same invoices. They
     * used to describe them differently — "Jatuh tempo 1 minggu dari sekarang"
     * against "dalam 8 hari" — and one bill described two ways on one visit is
     * a support call.
     */
    public function test_the_dashboard_and_the_invoice_page_describe_a_bill_identically(): void
    {
        $this->invoiceDue(now()->addDays(8)->toDateString());

        $this->actingAs($this->buyer, 'customer');

        $widget = Livewire::test(TagihanTerbuka::class)->html();

        $this->assertStringContainsString('Jatuh tempo dalam 8 hari', $widget);
        $this->assertStringContainsString('Jatuh tempo dalam 8 hari', $this->renderedTable());
    }

    /**
     * Same for an order with no price yet. The widget said "belum dihitung"
     * where the orders page said "menunggu konfirmasi".
     */
    public function test_the_dashboard_and_the_orders_page_describe_an_unpriced_order_identically(): void
    {
        $order = Order::factory()->create([
            'company_id' => $this->company->id,
            'nomor' => 'SO-LABEL-1',
        ]);

        $this->assertNull($order->confirmed_at);

        $this->actingAs($this->buyer, 'customer');

        $widget = Livewire::test(OrderTerakhir::class)->html();
        $page = Livewire::test(ListOrders::class)->html();

        foreach ([$widget, $page] as $html) {
            $this->assertStringContainsString('menunggu konfirmasi', $html);
            $this->assertStringNotContainsString('belum dihitung', $html);
        }
    }

    public function test_a_settled_invoice_says_so_rather_than_counting_days(): void
    {
        $this->invoiceDue(now()->subDays(10)->toDateString(), Invoice::STATUS_PAID);

        $this->actingAs($this->buyer, 'customer');

        $html = Livewire::test(ListTagihan::class)
            // The list defaults to unpaid only, which is what a buyer wants —
            // so clear the filter to see a settled one.
            ->filterTable('belum_lunas', false)
            ->html();

        $this->assertStringContainsString('Lunas', $html);
        $this->assertStringNotContainsString('Lewat 10 hari', $html);
    }
}
