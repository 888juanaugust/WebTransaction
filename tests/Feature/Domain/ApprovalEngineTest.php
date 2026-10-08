<?php

namespace Tests\Feature\Domain;

use App\Domain\Access\HakKhusus;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\Exceptions\DocumentLockedException;
use App\Domain\Printing\PrintJob;
use App\Filament\Resources\Settings\TransactionApprovers\Pages\ManageTransactionApprovers;
use App\Models\Approval\ApprovalDecision;
use App\Models\Approval\ApprovalRequest;
use App\Models\Company\TaxCode;
use App\Models\Company\TransactionApprover;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\Vendor;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Every registered document type waits under the governing rule's condition, and is not pulled, printed or settled until approved. */
class ApprovalEngineTest extends TestCase
{
    private ApprovalEngine $engine;

    private DocumentRepository $docs;

    private Vendor $vendor;

    private Item $item;

    private User $clerk;

    /** @var array<string, User> */
    private array $people = [];

    private AccessGroup $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->engine = app(ApprovalEngine::class);
        $this->docs = app(DocumentRepository::class);
        $this->actingAsAdmin();
        $this->vendor = $this->sampleVendor();
        $this->item = $this->sampleItem();
        $this->clerk = User::factory()->create(['access_type' => 'administrator', 'name' => 'Clerk']);
        foreach (['Ana', 'Ben', 'Cy', 'Dee'] as $name) {
            $this->people[$name] = User::factory()->create(['name' => $name]);
        }
        $this->finance = AccessGroup::query()->where('name', 'Finance')->firstOrFail();
        $this->finance->users()->attach($this->people['Dee']);
    }

    /** @param  list<string|AccessGroup>  $approvers  people by name, or a group, in approval order */
    private function rule(string $type, int $from, string $condition, array $approvers, bool $active = true): TransactionApprover
    {
        $rule = TransactionApprover::query()->create(['transaction_type' => $type, 'min_amount' => $from, 'rule' => $condition, 'is_active' => $active]);
        foreach ($approvers as $sort => $who) {
            $who instanceof AccessGroup
                ? $rule->groups()->attach($who->id, ['sort' => $sort])
                : $rule->approvers()->attach($this->people[$who]->id, ['sort' => $sort]);
        }

        return $rule;
    }

    private function order(int $price, int $qty = 1): PurchaseOrder
    {
        $this->actingAs($this->clerk);
        $po = PurchaseOrder::query()->create(['number' => 'PO-'.uniqid(), 'trans_date' => '2026-11-16', 'vendor_id' => $this->vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => $this->clerk->id]);
        $po->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_price' => $price, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $po->refreshTotal();
        $this->docs->created($po);

        return $po->fresh();
    }

    private function approveAs(string $name, $document): bool
    {
        $this->actingAs($this->people[$name]);
        $this->freshRequest();

        return $this->engine->approve($document->fresh(), $this->people[$name]);
    }

    public function test_without_a_rule_a_document_is_approved_on_save_and_the_highest_covering_rule_governs(): void
    {
        $po = $this->order(400_000);
        $this->assertTrue($this->engine->isApproved($po));
        $this->assertSame(ApprovalRequest::RULE_NONE, $this->engine->current($po)->rule);

        $this->rule('purchase_order', 0, TransactionApprover::ANY_ONE, ['Ana']);
        $big = $this->rule('purchase_order', 1_000_000, TransactionApprover::AT_LEAST_TWO, ['Ana', 'Ben', 'Cy']);
        $this->rule('purchase_order', 0, TransactionApprover::ANY_ONE, ['Cy'], active: false);

        $this->assertSame(TransactionApprover::ANY_ONE, $this->engine->current($this->order(500_000))->rule);
        $request = $this->engine->current($this->order(2_000_000));
        $this->assertSame($big->id, $request->transaction_approver_id, 'the covering rule with the highest amount governs');
        $this->assertSame(2, $request->required_count);
        $this->assertSame(['Ana', 'Ben', 'Cy'], collect($request->slots)->pluck('name')->all());
        $this->assertTrue($this->engine->isApproved($po), 'a rule added later does not reach back to a document already approved');
    }

    public function test_who_changed_the_document_does_not_approve_the_change(): void
    {
        $this->rule('purchase_order', 0, TransactionApprover::ANY_ONE, ['Ana', 'Ben']);
        $po = $this->order(1_000_000);

        // Ben, an approver who may edit others' documents, raises the price on the clerk's order: it waits for approval again, and not his.
        $editors = AccessGroup::query()->create(['name' => 'Editors']);
        $editors->syncSpecialRights([HakKhusus::EditOthersTransactions->value]);
        $editors->users()->attach($this->people['Ben']);
        $this->actingAs($this->people['Ben']);
        $this->freshRequest();
        $before = $this->docs->beforeUpdate($po->fresh());
        $po->lines()->first()->update(['unit_price' => 5_000_000]);
        $po->refreshTotal();
        $this->docs->updated($po->fresh(), $before);
        $this->assertSame('awaiting', $this->engine->status($po->fresh()));
        $this->assertFalse($this->engine->canApprove($po->fresh(), $this->people['Ben']));
        $this->assertThrows(fn () => $this->engine->approve($po->fresh(), $this->people['Ben']), RuntimeException::class, 'last changed');
        $this->assertTrue($this->approveAs('Ana', $po));
    }

    public function test_at_least_two_needs_two_different_people_and_never_the_one_who_entered_it(): void
    {
        $this->rule('purchase_order', 0, TransactionApprover::AT_LEAST_TWO, ['Ana', 'Ben']);
        $po = $this->order(1_000_000);
        $this->assertSame('awaiting', $this->engine->status($po));

        $this->assertFalse($this->approveAs('Ana', $po), 'one of two');
        $this->assertFalse($this->engine->canApprove($po->fresh(), $this->people['Ana']), 'the same person cannot count twice');
        $this->assertFalse($this->engine->canApprove($po->fresh(), $this->people['Cy']), 'someone the rule does not name');
        $this->assertTrue($this->approveAs('Ben', $po));
        $this->assertSame('approved', $this->engine->status($po->fresh()));

        // Segregation of duties: named, but entered it.
        $this->rule('purchase_order', 0, TransactionApprover::ANY_ONE, ['Dee'])->update(['min_amount' => 5_000_000]);
        auth()->forgetUser(); // promoted by the system: only an administrator makes an administrator
        $this->people['Dee']->forceFill(['access_type' => 'administrator'])->save();
        $this->clerk = $this->people['Dee'];
        $own = $this->order(6_000_000);
        try {
            $this->approveAs('Dee', $own);
            $this->fail('the person who entered it cannot approve it');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Segregation of duties', $e->getMessage());
        }
        // Nor rejects it: a rejection is a decision on it too.
        $this->assertFalse($this->engine->canReject($own->fresh(), $this->people['Dee']));
        $this->assertThrows(fn () => $this->engine->reject($own->fresh(), $this->people['Dee'], 'no'), RuntimeException::class, 'Segregation of duties');
    }

    public function test_every_slot_in_order_and_in_any_order_with_a_group_filled_by_one_member(): void
    {
        $this->rule('purchase_order', 0, TransactionApprover::IN_ORDER, ['Ben', $this->finance]);
        $po = $this->order(1_000_000);
        $this->assertFalse($this->engine->canApprove($po, $this->people['Dee']), 'the group comes second');
        try {
            $this->approveAs('Dee', $po);
            $this->fail('out of order');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('waits for Ben first', $e->getMessage());
        }
        $this->assertFalse($this->approveAs('Ben', $po));
        $this->assertTrue($this->approveAs('Dee', $po), 'any one member fills the group\'s place');

        TransactionApprover::query()->update(['is_active' => false]);
        $this->rule('purchase_order', 0, TransactionApprover::ANY_ORDER, ['Ana', $this->finance]);
        $po = $this->order(1_000_000);
        $this->assertFalse($this->approveAs('Dee', $po));
        $this->assertSame(['Ana'], $this->engine->progress($po->fresh())['waiting']);
        $this->assertTrue($this->approveAs('Ana', $po));
        $this->assertSame(2, ApprovalDecision::query()->where('approval_request_id', $this->engine->current($po)->id)->count());
    }

    public function test_a_waiting_document_is_not_pulled_printed_or_settled(): void
    {
        $this->rule('purchase_order', 0, TransactionApprover::ANY_ONE, ['Ana']);
        $this->rule('purchase_invoice', 0, TransactionApprover::ANY_ONE, ['Ana']);
        $po = $this->order(100_000, 2);

        $this->actingAs($this->clerk);
        $gr = GoodsReceipt::query()->create(['number' => 'GR-1', 'trans_date' => '2026-11-16', 'vendor_id' => $this->vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => $this->clerk->id]);
        $line = $po->lines()->first();
        $gr->lines()->create(['sort' => 0, 'item_id' => $line->item_id, 'quantity' => 2, 'unit_id' => $line->unit_id, 'base_quantity' => 2, 'unit_price' => 100_000, 'warehouse_id' => $line->warehouse_id, 'source_line_type' => 'purchase_order_line', 'source_line_id' => $line->id]);
        $gr->refreshTotal();
        try {
            DB::transaction(fn () => $this->docs->created($gr));
            $this->fail('pulling from an order that waits');
        } catch (DocumentLockedException $e) {
            $this->assertStringContainsString('not approved', $e->getMessage());
        }

        try {
            app(PrintJob::class)->prepare('purchase_order', $po->id, $this->clerk);
            $this->fail('printing an order that waits');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be printed', $e->getMessage());
        }

        $this->approveAs('Ana', $po);
        $this->actingAs($this->clerk);
        $this->docs->created($gr->fresh());
        $this->assertSame('processed', $po->fresh()->status, 'approved, the order is received');
        $this->assertSame('Purchase Order', app(PrintJob::class)->prepare('purchase_order', $po->id, $this->clerk)['title']);

        $bill = PurchaseInvoice::query()->create(['number' => 'BILL-1', 'trans_date' => '2026-11-16', 'vendor_id' => $this->vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => $this->clerk->id]);
        $bill->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 1, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 1, 'unit_price' => 50_000, 'warehouse_id' => Warehouse::default()->id]);
        $bill->refreshTotal();
        $this->docs->created($bill);
        $payment = PurchasePayment::query()->create(['number' => 'CB-1', 'trans_date' => '2026-11-16', 'vendor_id' => $this->vendor->id, 'bank_account_id' => Account::query()->where('no', '1102')->value('id'), 'created_by' => $this->clerk->id]);
        $payment->lines()->create(['sort' => 0, 'payable_type' => 'purchase_invoice', 'payable_id' => $bill->id, 'amount' => 50_000, 'discount' => 0]);
        $payment->refreshTotal();
        try {
            DB::transaction(fn () => $this->docs->created($payment));
            $this->fail('settling a bill that waits');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be settled', $e->getMessage());
        }
    }

    public function test_an_edit_of_the_amount_or_lines_asks_again_and_a_rejection_waits_for_a_new_version(): void
    {
        $this->rule('purchase_order', 0, TransactionApprover::ANY_ONE, ['Ana']);
        $po = $this->order(100_000);
        $this->approveAs('Ana', $po);
        $first = $this->engine->current($po);

        $this->actingAs($this->clerk);
        $before = $this->docs->beforeUpdate($po->fresh());
        $po->update(['description' => 'Only the note']);
        $this->docs->updated($po, $before);
        $this->assertSame($first->id, $this->engine->current($po)->id, 'a note changes nothing that was approved');

        $before = $this->docs->beforeUpdate($po->fresh());
        $po->lines()->first()->update(['quantity' => 3, 'base_quantity' => 3]);
        $po->refreshTotal();
        $this->docs->updated($po->fresh(), $before);
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertSame('awaiting', $this->engine->status($po->fresh()), 'a new quantity is a new approval');

        $this->actingAs($this->people['Ana']);
        $this->engine->reject($po->fresh(), $this->people['Ana'], 'Too many');
        $this->assertSame('rejected', $this->engine->status($po->fresh()));
        $this->assertFalse($this->engine->canApprove($po->fresh(), $this->people['Ana']), 'a rejected version is not approved later');
        $this->assertSame(2, ApprovalRequest::query()->where('approvable_type', 'purchase_order')->where('approvable_id', $po->id)->count(), 'the approved version is kept as history');
    }

    public function test_the_rule_screen_offers_only_registered_types_and_keeps_the_approval_order(): void
    {
        $types = array_map(fn ($t) => $t->value, $this->engine->transactionTypes());
        $this->assertContains('purchase_order', $types);
        $this->assertContains('stock_opname_result', $types);
        $this->assertNotContains('customer', $types, 'masters are not approved');
        $this->assertSame(['sales_return', 'purchase_return', 'vendor_claim'], TransactionApprover::query()->where('is_active', false)->orderBy('id')->pluck('transaction_type')->all(), 'the credit rules are seeded off');

        $this->actingAsAdmin();
        $ana = $this->people['Ana']->id;
        $ben = $this->people['Ben']->id;
        Livewire::test(ManageTransactionApprovers::class)
            ->mountAction('create')
            ->fillForm(['transaction_type' => 'purchase_order', 'min_amount' => 0, 'rule' => TransactionApprover::IN_ORDER, 'approvers' => [$ana, $ben], 'groups' => [$this->finance->id]])
            ->assertActionDataSet(fn (array $data) => array_column(array_values($data['approval_order']), 'slot') === ["user:{$ana}", "user:{$ben}", "group:{$this->finance->id}"])
            ->set('mountedActions.0.data.approval_order', ['a' => ['slot' => "group:{$this->finance->id}"], 'b' => ['slot' => "user:{$ben}"], 'c' => ['slot' => "user:{$ana}"]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $rule = TransactionApprover::query()->where('transaction_type', 'purchase_order')->firstOrFail();
        $po = $this->order(100_000);
        $this->assertSame(['Finance', 'Ben', 'Ana'], collect($this->engine->current($po)->slots)->pluck('name')->all(), 'the order chosen on the screen is the order of approval');
        $this->assertSame(0, (int) $rule->groups()->first()->pivot->sort);
    }
}
