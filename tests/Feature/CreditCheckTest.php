<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Credit\CreditChecker;
use App\Domain\Orders\OrderStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credit exposure is unpaid invoices plus confirmed-but-uninvoiced orders.
 *
 * Counting only invoices would let a customer confirm ten orders before any of
 * them are billed and walk straight through their limit.
 */
class CreditCheckTest extends TestCase
{
    use RefreshDatabase;

    private function checker(): CreditChecker
    {
        return app(CreditChecker::class);
    }

    public function test_a_customer_with_no_history_has_their_whole_limit(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();

        $this->assertSame(50_000_000, $this->checker()->available($company));
    }

    public function test_open_invoices_reduce_available_credit(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();

        Invoice::factory()->totalling(20_000_000)->create(['company_id' => $company->id]);

        $this->assertSame(30_000_000, $this->checker()->available($company));
    }

    public function test_payments_against_an_invoice_free_the_credit_back_up(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();
        $invoice = Invoice::factory()->totalling(20_000_000)->create(['company_id' => $company->id]);

        PaymentEntry::create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'amount_rupiah' => 15_000_000,
            'kind' => PaymentEntry::KIND_PAYMENT,
        ]);

        $this->assertSame(45_000_000, $this->checker()->available($company));
    }

    public function test_a_reversal_puts_the_exposure_back(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();
        $invoice = Invoice::factory()->totalling(20_000_000)->create(['company_id' => $company->id]);

        $payment = PaymentEntry::create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'amount_rupiah' => 20_000_000,
            'kind' => PaymentEntry::KIND_PAYMENT,
        ]);

        $this->assertSame(50_000_000, $this->checker()->available($company));

        // Bounced transfer: the original row is untouched, a negative one is
        // appended, and the exposure comes straight back.
        PaymentEntry::create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'amount_rupiah' => -20_000_000,
            'kind' => PaymentEntry::KIND_REVERSAL,
            'reverses_entry_id' => $payment->id,
        ]);

        $this->assertSame(30_000_000, $this->checker()->available($company));
    }

    public function test_confirmed_but_uninvoiced_orders_count_against_the_limit(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();

        Order::factory()
            ->status(OrderStatus::Confirmed)
            ->totalling(30_000_000)
            ->create(['company_id' => $company->id]);

        $this->assertSame(20_000_000, $this->checker()->available($company));
    }

    public function test_draft_orders_do_not_count(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();

        Order::factory()
            ->status(OrderStatus::Draft)
            ->totalling(30_000_000)
            ->create(['company_id' => $company->id]);

        $this->assertSame(50_000_000, $this->checker()->available($company));
    }

    public function test_an_order_within_the_limit_passes(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(10_000_000)
            ->create(['company_id' => $company->id]);

        $status = $this->checker()->check($order);

        $this->assertTrue($status->passes());
        $this->assertSame(40_000_000, $status->availableAfter());
    }

    public function test_an_order_over_the_limit_is_blocked(): void
    {
        $company = Company::factory()->creditLimit(10_000_000)->create();

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(15_000_000)
            ->create(['company_id' => $company->id]);

        $status = $this->checker()->check($order);

        $this->assertFalse($status->passes());
        $this->assertStringContainsString('Melebihi limit kredit', implode(' ', $status->blockers));
    }

    public function test_an_order_exactly_at_the_limit_passes(): void
    {
        $company = Company::factory()->creditLimit(10_000_000)->create();

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(10_000_000)
            ->create(['company_id' => $company->id]);

        $this->assertTrue($this->checker()->check($order)->passes());
        $this->assertSame(0, $this->checker()->check($order)->availableAfter());
    }

    public function test_the_order_being_checked_is_not_counted_against_itself(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();

        // Already confirmed, so it is in the "committed" set. Re-checking it
        // must not deduct the same 30 juta twice.
        $order = Order::factory()
            ->status(OrderStatus::Confirmed)
            ->totalling(30_000_000)
            ->create(['company_id' => $company->id]);

        $status = $this->checker()->check($order);

        $this->assertSame(50_000_000, $status->available());
        $this->assertTrue($status->passes());
    }

    public function test_a_merely_overdue_invoice_no_longer_blocks_ordering(): void
    {
        /*
         * The credit-sales reorganisation changed this on purpose. Buying on
         * account means invoices routinely run past their due date while the
         * customer keeps trading; the brake moved from "any overdue" to the
         * four-month freeze below. The limit still caps total exposure.
         */
        $company = Company::factory()->creditLimit(100_000_000)->create();

        Invoice::factory()->overdue()->totalling(1_000_000)->create(['company_id' => $company->id]);

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(1_000_000)
            ->create(['company_id' => $company->id]);

        $this->assertTrue($this->checker()->check($order)->passes());
    }

    public function test_a_debt_past_four_months_and_a_day_freezes_the_customer(): void
    {
        $company = Company::factory()->creditLimit(100_000_000)->create();

        Invoice::factory()->totalling(1_000_000)->create([
            'company_id' => $company->id,
            'issued_on' => today()->subMonths(4)->subDay(),
            'due_date' => today()->subMonths(3),
        ]);

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(1_000_000)
            ->create(['company_id' => $company->id]);

        $status = $this->checker()->check($order);

        // Plenty of headroom — the age of the debt is the blocker, and the
        // message names the invoice, because "you are blocked" without "by
        // what" is an angry phone call.
        $this->assertGreaterThan(0, $status->availableAfter());
        $this->assertFalse($status->passes());
        $this->assertStringContainsString('jatuh tempo keras', implode(' ', $status->blockers));
    }

    public function test_a_debt_of_exactly_four_months_does_not_freeze_yet(): void
    {
        /*
         * "Empat bulan plus satu hari" — the boundary is the day after, so an
         * off-by-one here would lock customers a day early, on the owner's
         * stated terms rather than the code's.
         */
        $company = Company::factory()->creditLimit(100_000_000)->create();

        Invoice::factory()->totalling(1_000_000)->create([
            'company_id' => $company->id,
            'issued_on' => today()->subMonths(4),
            'due_date' => today()->subMonths(3),
        ]);

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(1_000_000)
            ->create(['company_id' => $company->id]);

        $this->assertTrue($this->checker()->check($order)->passes());
    }

    public function test_a_customer_pending_approval_cannot_order_on_credit(): void
    {
        $company = Company::factory()->pending()->creditLimit(50_000_000)->create();

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(1_000_000)
            ->create(['company_id' => $company->id]);

        $status = $this->checker()->check($order);

        $this->assertFalse($status->passes());
        $this->assertStringContainsString('pending_approval', implode(' ', $status->blockers));
    }

    public function test_a_suspended_customer_cannot_order(): void
    {
        $company = Company::factory()->suspended()->creditLimit(50_000_000)->create();

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(1_000_000)
            ->create(['company_id' => $company->id]);

        $this->assertFalse($this->checker()->check($order)->passes());
    }

    public function test_void_invoices_do_not_count_as_exposure(): void
    {
        $company = Company::factory()->creditLimit(50_000_000)->create();

        Invoice::factory()->totalling(20_000_000)->create([
            'company_id' => $company->id,
            'status' => Invoice::STATUS_VOID,
        ]);

        $this->assertSame(50_000_000, $this->checker()->available($company));
    }

    public function test_a_zero_limit_customer_can_do_nothing_on_credit(): void
    {
        $company = Company::factory()->creditLimit(0)->create();

        $order = Order::factory()
            ->status(OrderStatus::Submitted)
            ->totalling(1)
            ->create(['company_id' => $company->id]);

        $this->assertFalse($this->checker()->check($order)->passes());
    }
}
