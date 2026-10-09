<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\TransactionApprover;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Who approves an order: the customer's marketing seat or an administrator; a customer without a seat follows the base's rules. */
class SeatApprovalTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 20);
    }

    public function test_the_customers_marketing_seat_approves(): void
    {
        $order = $this->order(5, $this->gudangJakarta);

        $this->assertTrue(app(ApprovalEngine::class)->canApprove($order, $this->marketing));
        $this->assertTrue(app(ApprovalEngine::class)->approve($order, $this->marketing));
        $this->assertSame('approved', $order->fresh()->approval_status);
        $this->assertSame($this->marketing->id, $order->fresh()->approved_by);
    }

    public function test_another_marketing_is_refused_the_owner_is_not(): void
    {
        $other = $this->member(CentralGroups::MARKETING, [$this->jakarta, $this->surabaya]);
        $order = $this->order(5, $this->gudangJakarta);

        try {
            app(ApprovalEngine::class)->approve($order, $other);
            $this->fail('not this customer\'s seat');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('marketing seat', $e->getMessage());
        }
        $this->assertSame('awaiting', $order->fresh()->approval_status);

        app(ApprovalEngine::class)->approve($order->fresh(), $this->owner);
        $this->assertSame('approved', $order->fresh()->approval_status);
    }

    public function test_sales_never_approves(): void
    {
        $order = $this->order(5, $this->gudangJakarta);

        $this->assertFalse(app(ApprovalEngine::class)->canApprove($order, $this->sales), 'the Sales group holds no approve right');
        $this->expectException(RuntimeException::class);
        app(ApprovalEngine::class)->approve($order, $this->sales);
    }

    public function test_a_customer_without_a_seat_follows_the_base_rules(): void
    {
        $this->customer->update(['marketing_user_id' => null]);
        $other = $this->member(CentralGroups::MARKETING, [$this->jakarta, $this->surabaya]);
        $order = $this->order(5, $this->gudangJakarta);

        $this->assertTrue(app(ApprovalEngine::class)->approve($order, $other), 'anyone with the approve right');

        $rule = TransactionApprover::query()->create(['transaction_type' => 'sales_order', 'min_amount' => 0, 'rule' => 'any_one', 'is_active' => true]);
        $rule->approvers()->attach($this->owner);
        $second = $this->order(5, $this->gudangJakarta);
        $this->assertFalse(app(ApprovalEngine::class)->canApprove($second, $other), 'a covering rule names who approves');
        $this->assertTrue(app(ApprovalEngine::class)->canApprove($second, $this->owner));
    }

    public function test_the_credit_check_still_runs_before_the_seat_approves(): void
    {
        $this->customer->update(['credit_limit_amount_enabled' => true, 'credit_limit_amount' => 500_000]);
        $order = $this->order(5, $this->gudangJakarta);
        $this->actingAs($this->marketing); // an administrator may save over the limit; the seat may not

        try {
            app(ApprovalEngine::class)->approve($order, $this->marketing);
            $this->fail('over the credit limit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('credit limit', $e->getMessage());
        }
        $this->assertSame('awaiting', $order->fresh()->approval_status);
    }

    public function test_with_the_rule_off_orders_are_approved_on_entry(): void
    {
        app(Preferensi::class)->set(PreferensiKey::SalesOrderApproval, false);

        $this->assertSame('approved', $this->order(5, $this->gudangJakarta)->approval_status);
    }
}
