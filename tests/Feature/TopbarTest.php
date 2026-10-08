<?php

namespace Tests\Feature;

use App\Domain\Posting\DocumentRepository;
use App\Livewire\ApprovalsWaiting;
use App\Models\Company\TransactionApprover;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** The workspace topbar: the approvals bell and who is signed in. */
class TopbarTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->enableAllModules();
        $this->admin = $this->actingAsAdmin();
    }

    private function order(string $number): PurchaseOrder
    {
        $vendor = $this->sampleVendor(['number' => 'V-'.$number]);
        $item = $this->sampleItem(['number' => 'I-'.$number]);
        $po = PurchaseOrder::query()->create(['number' => $number, 'trans_date' => '2026-11-10', 'vendor_id' => $vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => $this->admin->id]);
        $po->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 30, 'unit_id' => $item->unit1_id, 'base_quantity' => 30, 'unit_price' => 100_000, 'warehouse_id' => Warehouse::default()->id]);
        $po->refreshTotal();
        app(DocumentRepository::class)->created($po);

        return $po->fresh();
    }

    public function test_the_bell_lists_what_waits_for_the_user_and_nothing_for_whoever_entered_it(): void
    {
        $rule = TransactionApprover::query()->create(['transaction_type' => 'purchase_order', 'min_amount' => 1_000_000, 'rule' => TransactionApprover::ANY_ONE, 'is_active' => true]);
        $approver = $this->actingAsAdmin();
        $rule->approvers()->attach($approver->id, ['sort' => 0]);
        $this->actingAs($this->admin);
        $this->order('PO-2611-0001');

        $this->assertSame([], ApprovalsWaiting::itemsFor($this->admin), 'whoever entered it does not approve it');

        $this->actingAs($approver);
        $items = ApprovalsWaiting::itemsFor($approver);
        $this->assertCount(1, $items);
        $this->assertStringContainsString('PO-2611-0001', $items[0]['title']);
        $this->assertStringStartsWith('/admin/', $items[0]['url']);
        $this->assertStringEndsWith('/edit', $items[0]['url']);

        Livewire::withoutLazyLoading()->test(ApprovalsWaiting::class)
            ->assertSee('PO-2611-0001')
            ->assertSee(__('Waiting for your approval'));
    }

    public function test_an_empty_bell_says_so(): void
    {
        Livewire::withoutLazyLoading()->test(ApprovalsWaiting::class)->assertSee(__('Nothing is waiting for your approval.'));
    }

    public function test_the_topbar_names_the_user_and_their_role(): void
    {
        $this->admin->forceFill(['name' => 'Ayu Lestari'])->save();

        $html = view('filament.shell.user-bar')->render();

        $this->assertStringContainsString('Ayu Lestari', $html);
        $this->assertStringContainsString(__('Administrator'), $html);
    }
}
