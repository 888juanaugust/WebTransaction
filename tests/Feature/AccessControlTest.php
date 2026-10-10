<?php

namespace Tests\Feature;

use App\Domain\Access\AccessWindow;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Imports\MasterImporter;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\Exceptions\DocumentLockedException;
use App\Domain\Printing\PrintJob;
use App\Domain\Tax\TaxFilingService;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Reports\InventoryValue;
use App\Filament\Pages\Reports\TrialBalance;
use App\Filament\Pages\Workspace;
use App\Filament\Resources\Company\Branches\Pages\ManageBranches;
use App\Filament\Resources\GeneralLedger\JournalVouchers\JournalVoucherResource;
use App\Filament\Resources\Inventory\InventoryAdjustments\Pages\EditInventoryAdjustment;
use App\Filament\Resources\Inventory\Items\Pages\EditItem;
use App\Filament\Resources\Purchasing\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use App\Filament\Resources\Sales\Customers\Pages\EditCustomer;
use App\Filament\Resources\Sales\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Resources\Sales\SalesReceipts\Pages\CreateSalesReceipt;
use App\Filament\Resources\Settings\AccessGroups\AccessGroupResource;
use App\Filament\Resources\Settings\Users\Pages\EditUser;
use App\Filament\Support\BranchFields;
use App\Models\Company\AuditLog;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalVoucher;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** The special rights, branch limits and the access window are enforced, not only stored. */
class AccessControlTest extends TestCase
{
    private DocumentRepository $docs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->docs = app(DocumentRepository::class);
    }

    /** An operator in the named seeded groups. */
    /** A Sales operator stripped of Central's special rights: the base rules are tested on the right itself, not on the group's shape. */
    private function creditBlindSales(): User
    {
        AccessGroup::query()->where('name', 'Sales')->firstOrFail()->syncSpecialRights([]);

        return $this->operator('Sales');
    }

    private function operator(string ...$groups): User
    {
        $user = User::factory()->create();
        foreach ($groups as $group) {
            AccessGroup::query()->where('name', $group)->firstOrFail()->users()->attach($user);
        }
        $this->actingAs($user);
        $this->freshRequest();

        return $user;
    }

    private function voucher(string $date, ?int $branchId = null, string $number = 'JV-1'): JournalVoucher
    {
        $voucher = JournalVoucher::query()->create(['number' => $number, 'trans_date' => $date, 'branch_id' => $branchId, 'description' => 'Test', 'created_by' => auth()->id()]);
        $voucher->lines()->createMany([
            ['sort' => 0, 'account_id' => Account::query()->where('no', '6100')->value('id'), 'debit' => 10_000, 'credit' => 0],
            ['sort' => 1, 'account_id' => Account::query()->where('no', '1101')->value('id'), 'debit' => 0, 'credit' => 10_000],
        ]);

        return $voucher;
    }

    public function test_back_dating_takes_the_right_but_editing_a_past_document_on_its_own_date_does_not(): void
    {
        $this->operator('Finance');
        $this->docs->created($this->voucher('2026-11-16', number: 'JV-TODAY'));

        try {
            $this->docs->created($this->voucher('2026-11-10', number: 'JV-PAST'));
            $this->fail('an operator without the right cannot date a transaction before today');
        } catch (DocumentLockedException $e) {
            $this->assertStringContainsString('back-date', $e->getMessage());
        }

        $accountant = $this->operator('Accounting');
        $past = $this->voucher('2026-11-10', number: 'JV-PAST-2');
        $this->docs->created($past);
        $this->assertTrue(app(HakAkses::class)->allowsSpecial($accountant, HakKhusus::BackdateTransactions));

        $this->operator('Finance');
        $past->forceFill(['created_by' => auth()->id()])->saveQuietly();
        $this->docs->beforeUpdate($past->fresh(), Carbon::parse('2026-11-10'));
        $this->expectException(DocumentLockedException::class);
        $this->docs->beforeUpdate($past->fresh(), Carbon::parse('2026-11-09'));
    }

    public function test_deleting_a_posted_transaction_takes_the_right(): void
    {
        $this->operator('Finance', 'Accounting');
        $voucher = $this->voucher('2026-11-16');
        $this->docs->created($voucher);

        $this->operator('Finance');
        $voucher->forceFill(['created_by' => auth()->id()])->saveQuietly();
        try {
            $this->docs->delete($voucher->fresh());
            $this->fail('deleting a posted voucher takes the right');
        } catch (DocumentLockedException $e) {
            $this->assertStringContainsString('delete posted transactions', $e->getMessage());
        }

        $this->operator('Accounting');
        $voucher->forceFill(['created_by' => auth()->id()])->saveQuietly();
        $this->docs->delete($voucher->fresh());
        $this->assertNull(JournalVoucher::query()->find($voucher->id));
    }

    public function test_credit_data_and_export_are_hidden_without_their_rights(): void
    {
        $customer = $this->sampleCustomer(['credit_limit_amount_enabled' => true, 'credit_limit_amount' => 5_000_000]);

        $this->creditBlindSales();
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertFormFieldHidden('credit_limit_amount')
            ->assertFormFieldHidden('credit_limit_mode')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(5_000_000, (int) $customer->fresh()->credit_limit_amount, 'a hidden field keeps its value');

        $this->operator('Sales', 'Finance');
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])->assertFormFieldVisible('credit_limit_mode');

        $readers = AccessGroup::query()->create(['name' => 'Report readers']);
        $readers->syncRights([MenuKey::ReportCatalogue->value => ['view']]);
        $this->operator('Report readers');
        Livewire::test(TrialBalance::class)->assertActionHidden('export');

        $readers->syncSpecialRights([HakKhusus::ExportData->value]);
        $this->freshRequest();
        Livewire::test(TrialBalance::class)->assertActionVisible('export');
    }

    public function test_only_an_administrator_grants_administrator_or_changes_groups(): void
    {
        $admin = $this->actingAsAdmin();
        $managers = AccessGroup::query()->create(['name' => 'User managers']);
        $managers->syncRights([MenuKey::Users->value => ['view', 'create', 'update'], MenuKey::AccessGroups->value => ['view', 'create', 'update', 'delete']]);
        $manager = $this->operator('User managers');

        $refused = function (callable $change, string $message): void {
            try {
                $change();
                $this->fail("refused: {$message}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, implode(' ', Arr::flatten($e->errors())));
            }
        };
        $refused(fn () => $manager->forceFill(['access_type' => 'administrator'])->save(), 'Only an administrator makes');
        $refused(fn () => User::factory()->create(['access_type' => 'administrator']), 'Only an administrator makes');
        $refused(fn () => $admin->forceFill(['password' => 'taken-over-123'])->save(), 'Only an administrator changes');
        $this->assertFalse($manager->fresh()->isAdministrator());

        // Through the screen the access type and groups are locked, and a changed value is not saved.
        Livewire::test(EditUser::class, ['record' => $manager->getRouteKey()])
            ->assertFormFieldIsDisabled('access_type')
            ->assertFormFieldIsDisabled('accessGroups')
            ->set('data.access_type', 'administrator')
            ->call('save');
        $this->assertFalse($manager->fresh()->isAdministrator());
        $this->assertFalse(AccessGroupResource::canEdit($managers));
        $this->assertFalse(AccessGroupResource::canCreate());

        // The last administrator is never demoted, even by themselves.
        $this->actingAs($admin);
        User::query()->where('access_type', 'administrator')->whereKeyNot($admin->id)->update(['access_type' => 'operator']);
        $refused(fn () => $admin->forceFill(['access_type' => 'operator'])->save(), 'only active administrator');
    }

    public function test_a_password_someone_else_chose_is_changed_before_anything_else(): void
    {
        $admin = $this->actingAsAdmin();
        $admin->forceFill(['password' => 'chosen-by-installer', 'password_change_required' => true])->save();
        $this->freshRequest();

        $this->get(CustomerResource::getUrl())->assertRedirect(EditProfile::getUrl());
        $this->withHeader('X-Livewire', '1')->get(CustomerResource::getUrl())->assertRedirect(EditProfile::getUrl()); // a header proves nothing
        // The profile page stands alone: reopening it in the workspace would only be sent back here, round and round.
        $this->get(EditProfile::getUrl())->assertOk()->assertDontSee('#open=', false);

        // The installer's password was just typed to sign in; the first setup does not ask for it again.
        Livewire::test(EditProfile::class)
            ->assertFormFieldHidden('currentPassword')
            ->fillForm(['password' => 'my-own-password-1', 'passwordConfirmation' => 'my-own-password-1'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(Workspace::getUrl());
        $this->assertFalse($admin->fresh()->password_change_required, 'changed: the screens open again');

        // From then on a password change confirms the current one.
        $this->freshRequest();
        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'another-one-2', 'passwordConfirmation' => 'another-one-2'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);
    }

    public function test_administrators_can_be_held_to_a_second_factor(): void
    {
        $admin = $this->actingAsAdmin();
        app(Preferensi::class)->set(PreferensiKey::AdministratorTwoFactor, true);
        $this->freshRequest();

        $this->get(CustomerResource::getUrl())->assertRedirect(EditProfile::getUrl());
        $this->get(EditProfile::getUrl())->assertOk()->assertSee(__('Administrators sign in with a second factor: set up an authenticator app below before anything else.'));

        $admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save(); // an authenticator app set up
        $this->freshRequest();
        $this->get(CustomerResource::getUrl())->assertOk();

        $this->operator('Sales'); // operators are not held to it
        $this->get(CustomerResource::getUrl())->assertOk();
    }

    public function test_cost_and_credit_data_never_reach_someone_without_the_right(): void
    {
        $this->actingAsAdmin();
        $customer = $this->sampleCustomer(['credit_limit_amount' => 987_654_321, 'credit_limit_amount_enabled' => true]);

        $this->creditBlindSales(); // edits customers, no "see credit data", no "see cost"
        $page = Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()]);
        $this->assertNull($page->get('data.credit_limit_amount'));
        $page->assertDontSee('987654321')->assertDontSee('987.654.321');
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame(987_654_321, (int) $customer->fresh()->credit_limit_amount, 'and saving leaves it as it was');

        $this->operator('Finance'); // reads the reports, no "see cost"
        Livewire::test(InventoryValue::class)->assertTableColumnHidden('avg_cost')->assertTableColumnHidden('value')->assertTableColumnVisible('quantity');
    }

    public function test_a_storekeeper_without_the_cost_right_never_gets_a_cost_or_purchase_price(): void
    {
        $this->actingAsAdmin();
        $vendor = $this->sampleVendor();
        $item = $this->sampleItem(['purchase_price' => 66_000]);
        $order = PurchaseOrder::query()->create(['number' => 'PO-1', 'trans_date' => '2026-11-10', 'vendor_id' => $vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $order->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 4, 'unit_id' => $item->unit1_id, 'base_quantity' => 4, 'unit_price' => 77_000, 'warehouse_id' => Warehouse::default()->id]);
        $order->refreshTotal();
        $this->docs->created($order);
        $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-1', 'trans_date' => '2026-11-10', 'created_by' => auth()->id()]);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $item->id, 'adjustment_type' => 'quantity', 'quantity' => 2, 'unit_id' => $item->unit1_id, 'base_quantity' => 2, 'unit_cost' => 55_555, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $this->docs->created($adjustment);
        $item->openingStocks()->create(['trans_date' => '2026-11-01', 'quantity' => 1, 'unit_cost' => 44_444, 'warehouse_id' => Warehouse::default()->id]);

        $storekeepers = AccessGroup::query()->create(['name' => 'Storekeepers']);
        $storekeepers->syncRights([MenuKey::GoodsReceipts->value => ['view', 'create', 'update'], MenuKey::InventoryAdjustments->value => ['view', 'create', 'update'], MenuKey::ItemsAndServices->value => ['view', 'update'], MenuKey::PurchaseOrders->value => ['view']]);
        $keeper = User::factory()->create();
        $storekeepers->users()->attach($keeper);
        $this->actingAs($keeper);
        $this->freshRequest();

        $receipt = Livewire::withQueryParams(['source' => $order->id])->test(CreateGoodsReceipt::class);
        $this->assertEquals(0, Arr::first($receipt->get('data.lines'))['unit_price'] ?? 0, 'the order price is not on the page');
        $receipt->assertDontSee('77000')->call('create')->assertHasNoFormErrors();
        $this->assertSame('77000.0000', (string) GoodsReceipt::query()->sole()->lines()->sole()->unit_price, 'the server takes it from the order');

        $edit = Livewire::test(EditInventoryAdjustment::class, ['record' => $adjustment->getRouteKey()]);
        $this->assertNull(Arr::first($edit->get('data.lines'))['unit_cost']);
        $edit->assertDontSee('55555')->assertDontSee('55.555');
        $this->assertNull(Arr::first(Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])->get('data.openingStocks'))['unit_cost']);
    }

    public function test_an_import_changes_only_what_the_user_may_change(): void
    {
        $this->actingAsAdmin();
        $customer = $this->sampleCustomer(['credit_limit_amount' => 10_000_000, 'credit_limit_amount_enabled' => true]);
        $csv = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        $write = function (array $row) use ($csv): void {
            $out = fopen($csv, 'w');
            fputcsv($out, MasterImporter::columns('customers'), ',', '"', '\\');
            fputcsv($out, $row, ',', '"', '\\');
            fclose($out);
        };

        $this->creditBlindSales(); // creates and updates customers, no "see credit data"
        $write([$customer->number, 'Acme Trading', '', '', '', '', '', '', '', '', '', '', '999999999', '']);
        $result = app(MasterImporter::class)->import('customers', $csv);
        $this->assertSame(0, $result['updated']);
        $this->assertStringContainsString('see credit data', $result['errors'][0]);
        $this->assertSame(10_000_000, (int) $customer->fresh()->credit_limit_amount);

        $write([$customer->number, 'Acme Trading Renamed', '', '', '', '', '', '', '', '', '', '', '', '']);
        $this->assertSame(1, app(MasterImporter::class)->import('customers', $csv)['updated'], 'without the column, the row goes through');
        $this->assertSame(10_000_000, (int) $customer->fresh()->credit_limit_amount);
        @unlink($csv);
    }

    public function test_the_print_page_needs_sign_in_a_signed_link_and_the_branch(): void
    {
        $head = Branch::default();
        $east = Branch::query()->create(['name' => 'East', 'used_all_user' => false]);
        $this->actingAsAdmin();
        $mine = $this->voucher('2026-11-16', $head->id, 'JV-HEAD');
        $this->docs->created($mine);
        $theirs = $this->voucher('2026-11-16', $east->id, 'JV-EAST');
        $this->docs->created($theirs);

        $this->operator('Accounting');
        $this->get(PrintJob::url($mine))->assertOk()->assertSee('JV-HEAD');
        $this->get(PrintJob::url($theirs))->assertNotFound();
        $this->get(route('filament.admin.print', ['alias' => 'journal_voucher', 'id' => $mine->id]))->assertForbidden();

        auth()->logout();
        $this->freshRequest();
        $this->get(PrintJob::url($mine))->assertRedirect();
    }

    public function test_a_user_limited_to_some_branches_sees_and_books_only_those(): void
    {
        $head = Branch::default();
        $east = Branch::query()->create(['name' => 'East', 'used_all_user' => false]);
        $this->actingAsAdmin();
        $this->voucher('2026-11-16', $head->id, 'JV-HEAD')->save();
        $this->voucher('2026-11-16', $east->id, 'JV-EAST')->save();
        $this->voucher('2026-11-16', null, 'JV-NONE')->save();
        $this->assertSame(['JV-EAST', 'JV-HEAD', 'JV-NONE'], JournalVoucherResource::getEloquentQuery()->orderBy('number')->pluck('number')->all(), 'an administrator sees every branch');
        $this->assertNull(BranchFields::reportBranch(null), 'and reports on all of them');

        $clerk = $this->operator('Accounting');
        $this->assertSame([$head->id], Branch::limitsOf($clerk));
        $this->assertSame(['JV-HEAD', 'JV-NONE'], JournalVoucherResource::getEloquentQuery()->orderBy('number')->pluck('number')->all());
        $this->assertSame($head->id, BranchFields::reportBranch(null), 'a limited user reports on one of their branches');
        $this->assertSame($head->id, BranchFields::reportBranch($east->id), 'never on another');

        try {
            $this->docs->created($this->voucher('2026-11-16', $east->id, 'JV-EAST-2'));
            $this->fail('booking in a branch the user is not assigned to');
        } catch (DocumentLockedException $e) {
            $this->assertStringContainsString('not assigned', $e->getMessage());
        }

        $east->users()->attach($clerk);
        $this->assertNull(Branch::limitsOf($clerk), 'assigned to every closed branch, the user is not limited');
        $this->docs->created($this->voucher('2026-11-16', $east->id, 'JV-EAST-3'));
        $this->assertNotNull(Warehouse::default());

        // Someone limited to no branch at all reports on none, never on all of them.
        $head->forceFill(['used_all_user' => false])->saveQuietly();
        $this->operator('Accounting');
        $this->assertSame(-1, BranchFields::reportBranch(null));
    }

    public function test_a_create_page_opened_from_a_document_in_another_branch_starts_empty(): void
    {
        $east = Branch::query()->create(['name' => 'East', 'used_all_user' => false]);
        $this->actingAsAdmin();
        $customer = $this->sampleCustomer();
        $item = $this->sampleItem();
        $order = SalesOrder::query()->create(['number' => 'SO-EAST', 'trans_date' => '2026-11-10', 'customer_id' => $customer->id, 'branch_id' => $east->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $order->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 1, 'unit_id' => $item->unit1_id, 'base_quantity' => 1, 'unit_price' => 123_456, 'warehouse_id' => Warehouse::default()->id]);
        $this->docs->created($order);
        $invoice = SalesInvoice::query()->create(['number' => 'INV-EAST', 'trans_date' => '2026-11-10', 'customer_id' => $customer->id, 'branch_id' => $east->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 1, 'unit_id' => $item->unit1_id, 'base_quantity' => 1, 'unit_price' => 1_000, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();

        $this->operator('Sales', 'Finance'); // the head office only
        Livewire::withQueryParams(['source' => 'order:'.$order->id])->test(CreateSalesInvoice::class)
            ->assertSchemaStateSet(['customer_id' => null])->assertDontSee('123456')->assertDontSee('123.456');
        Livewire::withQueryParams(['source' => 'sales_invoice:'.$invoice->id])->test(CreateSalesReceipt::class)
            ->assertSchemaStateSet(['customer_id' => null]);

        $east->users()->attach(auth()->user());
        $this->freshRequest();
        Livewire::withQueryParams(['source' => 'order:'.$order->id])->test(CreateSalesInvoice::class)->assertSchemaStateSet(['customer_id' => $customer->id]);
    }

    public function test_only_an_administrator_says_who_may_use_a_branch(): void
    {
        $east = Branch::query()->create(['name' => 'East', 'used_all_user' => false]);
        $this->actingAsAdmin();
        $keepers = AccessGroup::query()->create(['name' => 'Branch keepers']);
        $keepers->syncRights([MenuKey::Branches->value => ['view', 'update']]);
        $clerk = User::factory()->create();
        $keepers->users()->attach($clerk);
        $this->actingAs($clerk);
        $this->freshRequest();

        Livewire::test(ManageBranches::class)
            ->mountTableAction('edit', $east)
            ->setTableActionData(['used_all_user' => true]) // sent anyway
            ->callMountedTableAction();
        $this->assertFalse($east->fresh()->used_all_user, 'a disabled list is not saved');
        $this->assertSame([], $east->users()->pluck('users.id')->all());
        $this->assertThrows(fn () => $east->fresh()->update(['used_all_user' => true]), ValidationException::class, 'Only an administrator');
    }

    public function test_only_an_administrator_sets_another_users_password_or_email(): void
    {
        $admin = $this->actingAsAdmin();
        $colleague = User::factory()->create(['email' => 'approver@example.test']);
        $managers = AccessGroup::query()->create(['name' => 'User managers']);
        $managers->syncRights([MenuKey::Users->value => ['view', 'create', 'update']]);
        $manager = User::factory()->create();
        $managers->users()->attach($manager);
        $this->actingAs($manager);
        $this->freshRequest();

        Livewire::test(EditUser::class, ['record' => $colleague->getRouteKey()])
            ->assertFormFieldIsDisabled('password')->assertFormFieldIsDisabled('email')
            ->fillForm(['password' => 'taken-over-12345', 'email' => 'mine@example.test'])
            ->call('save');
        $this->assertSame('approver@example.test', $colleague->fresh()->email);
        $this->assertFalse(Hash::check('taken-over-12345', $colleague->fresh()->password));
        $this->assertThrows(fn () => $colleague->fresh()->update(['password' => 'taken-over-12345']), ValidationException::class, 'Only an administrator');

        // An administrator's reset is the user's to replace at the next sign-in.
        $this->actingAs($admin);
        $this->freshRequest();
        Livewire::test(EditUser::class, ['record' => $colleague->getRouteKey()])
            ->fillForm(['password' => 'temporary-12345'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($colleague->fresh()->password_change_required);
    }

    public function test_the_access_window_turns_operators_away_outside_their_hours(): void
    {
        $preferensi = app(Preferensi::class);
        $preferensi->set(PreferensiKey::AccessRestriction, 'time_window');
        $preferensi->set(PreferensiKey::AccessFrom, '08:00');
        $preferensi->set(PreferensiKey::AccessUntil, '17:00');
        $window = app(AccessWindow::class);

        $admin = $this->actingAsAdmin();
        $clerk = $this->operator('Finance');
        $this->assertTrue($window->allows($clerk, Carbon::parse('2026-11-16 09:00')));
        $this->assertFalse($window->allows($clerk, Carbon::parse('2026-11-16 17:00')), 'the window closes at its end');
        $this->assertTrue($window->allows($admin, Carbon::parse('2026-11-16 23:00')), 'an administrator is never turned away');

        $night = AccessGroup::query()->create(['name' => 'Night shift', 'restriction_type' => 'time_window', 'restricted_from' => '22:00', 'restricted_until' => '02:00']);
        $night->users()->attach($clerk);
        $this->assertTrue($window->allows($clerk, Carbon::parse('2026-11-16 23:30')), 'any group allowing the moment is enough; the window runs past midnight');
        $this->assertTrue($window->allows($clerk, Carbon::parse('2026-11-17 01:30')));
        $this->assertFalse($window->allows($clerk, Carbon::parse('2026-11-17 03:00')));

        $this->travelTo(Carbon::parse('2026-11-17 03:00'));
        $this->get('/admin/dashboard')->assertForbidden()->assertSee('08:00–17:00');
        $this->travelTo(Carbon::parse('2026-11-17 09:00'));
        $this->get('/admin/dashboard')->assertOk();

        $preferensi->set(PreferensiKey::AccessRestriction, 'all');
        $night->users()->detach($clerk);
        $this->assertFalse($window->allows($clerk, Carbon::parse('2026-11-17 09:00')), 'restricted for everyone');
    }

    public function test_an_invoice_with_a_tax_serial_is_locked_until_the_serial_is_cleared(): void
    {
        $this->actingAsAdmin();
        $customer = $this->sampleCustomer();
        $item = $this->sampleItem(['item_type' => 'service']);
        $invoice = SalesInvoice::query()->create(['number' => 'INV-1', 'trans_date' => '2026-11-16', 'customer_id' => $customer->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 1, 'unit_id' => $item->unit1_id, 'base_quantity' => 1, 'unit_price' => 100_000, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        $this->docs->created($invoice);

        $invoice->forceFill(['nsfp' => '04002600000123'])->saveQuietly();
        $this->assertStringContainsString('04002600000123', (string) $this->docs->lockReason($invoice->fresh()));

        app(TaxFilingService::class)->clearSerial($invoice->fresh());
        $this->assertNull($invoice->fresh()->nsfp);
        $this->assertNull($this->docs->lockReason($invoice->fresh()));
        $this->assertTrue(AuditLog::query()->where('action', 'tax_serial_cleared')->where('meta->serial', '04002600000123')->exists(), 'the cleared serial is kept in the activity log');
    }
}
