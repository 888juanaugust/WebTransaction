<?php

namespace Tests\Feature\Domain;

use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Audit\Auditor;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\PeriodLock;
use App\Filament\Resources\Settings\Users\Pages\EditUser;
use App\Models\Company\AuditLog;
use App\Models\Company\Branch;
use App\Models\Company\Employee;
use App\Models\Company\Fob;
use App\Models\Inventory\Unit;
use App\Models\Purchasing\VendorBankAccount;
use App\Models\Sales\SalesOrder;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_master_changes_are_logged_with_before_and_after(): void
    {
        $admin = $this->actingAsAdmin();

        $fob = Fob::query()->create(['name' => 'Destination']);
        $fob->update(['name' => 'Destination (port)']);
        $fob->delete();

        $logs = AuditLog::query()->where('document_type', 'fob')->where('document_id', $fob->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame('Destination', $logs[0]->reference);
        $this->assertSame(['name' => 'Destination'], $logs[1]->meta['before']);
        $this->assertSame(['name' => 'Destination (port)'], $logs[1]->meta['after']);
        $this->assertSame($admin->id, $logs[0]->user_id);
    }

    public function test_rows_a_master_holds_are_logged_on_the_master(): void
    {
        $this->seed();
        $this->actingAsAdmin();
        $item = $this->sampleItem();
        $ctn = Unit::query()->where('name', 'CTN')->firstOrFail();
        $unit = $item->units()->create(['unit_id' => $ctn->id, 'ratio' => 12, 'sell_price' => 0]);
        $unit->update(['ratio' => 24]);
        $unit->delete();
        $vendor = $this->sampleVendor();
        VendorBankAccount::query()->create(['vendor_id' => $vendor->id, 'bank_account' => '123-456']);

        $logs = AuditLog::query()->where('document_type', $item->getMorphClass())->where('document_id', $item->id)->where('action', 'like', 'line_%')->orderBy('id')->get();
        $this->assertSame(['line_added', 'line_changed', 'line_removed'], $logs->pluck('action')->all());
        $this->assertSame('item_units', $logs[1]->meta['part']);
        $this->assertSame(['ratio' => '12.000000'], $logs[1]->meta['before']);
        $this->assertSame($item->auditReference(), $logs[0]->reference);
        // A bank account number is encrypted where it is kept, and never written into the log.
        $added = AuditLog::query()->where('document_type', $vendor->getMorphClass())->where('document_id', $vendor->id)->where('action', 'line_added')->sole();
        $this->assertStringNotContainsString('123-456', json_encode($added->meta));
        $this->assertNotSame('123-456', DB::table('vendor_bank_accounts')->where('vendor_id', $vendor->id)->value('bank_account'));
        $this->assertSame('123-456', VendorBankAccount::query()->where('vendor_id', $vendor->id)->value('bank_account'));
    }

    public function test_a_persons_national_and_tax_ids_are_encrypted_and_never_logged(): void
    {
        $this->seed();
        $this->actingAsAdmin();
        $employee = Employee::query()->create(['number' => 'EMP-1', 'name' => 'Rina', 'nik_no' => '3201010190000021', 'npwp_no' => '09.876.543.2-109.000']);
        $employee->update(['nik_no' => '3201010190000099']);

        $raw = DB::table('employees')->where('id', $employee->id)->first(['nik_no', 'npwp_no']);
        $this->assertStringNotContainsString('3201010190000099', (string) $raw->nik_no, 'encrypted where it is kept');
        $this->assertStringNotContainsString('109.000', (string) $raw->npwp_no);
        $this->assertSame('3201010190000099', $employee->fresh()->nik_no, 'and read back as it was typed');

        $log = AuditLog::query()->where('document_type', $employee->getMorphClass())->where('document_id', $employee->id)->where('action', 'updated')->sole();
        $this->assertStringNotContainsString('32010101900000', json_encode($log->meta), 'the log says it changed, not what it was');
        $this->assertArrayHasKey('nik_no', $log->meta['after']);
    }

    public function test_rights_and_memberships_are_logged_with_what_changed(): void
    {
        $this->seed();
        $this->actingAsAdmin();
        $group = AccessGroup::query()->create(['name' => 'Clerks']);
        $group->syncRights([MenuKey::SalesInvoices->value => ['view']]);
        $group->syncRights([MenuKey::SalesInvoices->value => ['view', 'create'], MenuKey::Customers->value => ['view']]);
        $group->syncRights([MenuKey::SalesInvoices->value => ['view', 'create'], MenuKey::Customers->value => ['view']]);
        $group->syncSpecialRights([HakKhusus::SeeCost->value]);

        $rights = AuditLog::query()->where('document_type', $group->getMorphClass())->where('document_id', $group->id)->where('action', 'rights_changed')->orderBy('id')->get();
        $this->assertCount(2, $rights, 'saving the same rights again changes nothing');
        $this->assertEquals([MenuKey::SalesInvoices->value => ['view'], MenuKey::Customers->value => []], $rights[1]->meta['before']);
        $this->assertSame(['added' => [HakKhusus::SeeCost->value], 'removed' => []], AuditLog::query()->where('document_id', $group->id)->where('action', 'special_rights_changed')->sole()->meta);

        $user = User::factory()->create();
        $branch = Branch::query()->firstOrFail();
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['accessGroups' => [$group->id], 'branches' => [$branch->id]])
            ->call('save')->assertHasNoFormErrors();
        $log = AuditLog::query()->where('document_id', $user->id)->where('action', 'memberships_changed')->sole();
        $this->assertSame(['groups' => [], 'branches' => []], $log->meta['before']);
        $this->assertSame(['groups' => ['Clerks'], 'branches' => [$branch->name]], $log->meta['after']);
    }

    public function test_a_document_is_logged_once_and_a_closed_month_keeps_even_those_that_post_nothing(): void
    {
        $this->seed();
        $this->actingAsAdmin();
        $this->travelTo(Carbon::parse('2026-12-05 10:00:00'));
        $docs = app(DocumentRepository::class);
        $order = SalesOrder::query()->create(['number' => 'SO-1', 'trans_date' => '2026-11-10', 'customer_id' => $this->sampleCustomer()->id, 'created_by' => auth()->id()]);
        $docs->created($order);
        $this->assertSame(['created'], AuditLog::query()->where('document_type', $order->getMorphClass())->where('document_id', $order->id)->pluck('action')->all());

        app(PeriodLock::class)->close(2026, 11);
        $this->assertThrows(fn () => $docs->delete($order->fresh()), \RuntimeException::class, 'closed');
        $this->assertNotNull($order->fresh());
    }

    public function test_the_log_refuses_updates_and_deletes(): void
    {
        $log = Auditor::log('created', null, 'test');

        // Each attempt in its own savepoint, so the refused statement does not abort the test's transaction.
        try {
            DB::transaction(fn () => AuditLog::query()->whereKey($log->id)->update(['action' => 'tampered']));
            $this->fail('UPDATE should have been refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::transaction(fn () => AuditLog::query()->whereKey($log->id)->delete());
            $this->fail('DELETE should have been refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::statement('TRUNCATE audit_logs'));
            $this->fail('TRUNCATE should have been refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only: TRUNCATE refused', $e->getMessage());
        }

        $this->assertSame('created', $log->fresh()->action);
    }
}
