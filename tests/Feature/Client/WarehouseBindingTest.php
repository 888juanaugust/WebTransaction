<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Warehouse\WarehouseBinder;
use App\Client\Domain\Warehouse\WarehouseScope;
use App\Client\Filament\Pages\WarehouseAccounts;
use App\Models\Company\AuditLog;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** One warehouse, one account: binding, the hand-over, the reactivation guard and the scope a bound account gets. */
class WarehouseBindingTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
    }

    private function binder(): WarehouseBinder
    {
        return app(WarehouseBinder::class);
    }

    public function test_an_administrator_binds_a_warehouse_member_to_one_warehouse_in_its_branch(): void
    {
        $gudang = $this->member(CentralGroups::WAREHOUSE, [$this->jakarta, $this->surabaya]);

        $this->binder()->bind($this->gudangSurabaya, $gudang, $this->owner);

        $this->assertTrue(WarehouseScope::of($gudang)->is($this->gudangSurabaya));
        $this->assertSame([$this->surabaya->id], $gudang->branches()->pluck('branches.id')->all(), 'put in the warehouse\'s branch');
        $this->assertTrue($this->binder()->holder($this->gudangSurabaya)->is($gudang));
        $this->assertContains('warehouse_bound', AuditLog::query()->pluck('action')->all());

        $this->binder()->bind($this->gudangJakarta, $gudang, $this->owner);
        $this->assertTrue(WarehouseScope::of($gudang)->is($this->gudangJakarta), 'bound to one warehouse alone');
        $this->assertNull($this->binder()->holder($this->gudangSurabaya));
        $this->assertNull(WarehouseScope::of($this->inventory), 'only a Warehouse account is scoped');
        $this->assertNull(WarehouseScope::of($this->owner));
    }

    public function test_a_warehouse_holds_one_active_account_and_a_hand_over_deactivates_the_old_one_first(): void
    {
        $old = $this->member(CentralGroups::WAREHOUSE, [$this->jakarta]);
        $new = $this->member(CentralGroups::WAREHOUSE, [$this->jakarta]);
        $this->binder()->bind($this->gudangJakarta, $old, $this->owner);

        try {
            $this->binder()->bind($this->gudangJakarta, $new, $this->owner);
            $this->fail('two accounts on one warehouse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('one warehouse, one account', $e->getMessage());
        }

        $old->forceFill(['is_active' => false])->save();
        $this->binder()->bind($this->gudangJakarta, $new, $this->owner);
        $this->assertTrue($this->binder()->holder($this->gudangJakarta)->is($new));

        try {
            $old->forceFill(['is_active' => true])->save();
            $this->fail('the old account came back next to the new one');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('one warehouse, one account', $e->getMessage());
        }
        $this->assertFalse($old->fresh()->is_active);

        $this->binder()->unbind($new, $this->owner);
        $old->forceFill(['is_active' => true])->save();
        $this->assertTrue($old->fresh()->is_active, 'free again once the warehouse is unbound');
    }

    public function test_only_an_administrator_binds_and_only_a_warehouse_member_is_bound(): void
    {
        $gudang = $this->member(CentralGroups::WAREHOUSE, [$this->jakarta]);
        try {
            $this->binder()->bind($this->gudangJakarta, $gudang, $this->inventory);
            $this->fail('inventory bound');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only an administrator', $e->getMessage());
        }
        try {
            $this->binder()->bind($this->gudangJakarta, $this->sales, $this->owner);
            $this->fail('a sales user bound');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Warehouse group', $e->getMessage());
        }

        Livewire::test(WarehouseAccounts::class)->assertOk()
            ->assertSee('Gudang Jakarta')
            ->callTableAction('bind', $this->gudangJakarta, ['user_id' => $gudang->id])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($this->binder()->holder($this->gudangJakarta)->is($gudang));

        $this->actingAs($this->inventory);
        $this->get('/admin/client/warehouse-accounts')->assertForbidden();
    }
}
