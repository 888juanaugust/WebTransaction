<?php

namespace Tests\Feature\Domain;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Models\Company\TaxCode;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Database\Seeders\Defaults\AccessGroupSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HakAksesTest extends TestCase
{
    public function test_an_operator_without_groups_has_no_rights_and_an_administrator_has_all(): void
    {
        $operator = User::factory()->create();
        $admin = User::factory()->create(['access_type' => 'administrator']);
        $akses = app(HakAkses::class);

        $this->assertFalse($akses->allows($operator, MenuKey::TaxCodes, Hak::View));
        $this->assertTrue($akses->allows($admin, MenuKey::TaxCodes, Hak::Delete));
        $this->assertTrue($akses->allowsSpecial($admin, HakKhusus::OpenClosedPeriod));
        $this->assertFalse($akses->allows(null, MenuKey::TaxCodes, Hak::View));
    }

    public function test_group_rights_grant_and_overrides_win(): void
    {
        $user = User::factory()->create();
        $group = AccessGroup::query()->create(['name' => 'Clerks']);
        $group->syncRights([MenuKey::TaxCodes->value => ['view', 'create'], MenuKey::Branches->value => ['view']]);
        $group->syncSpecialRights(['see_cost']);
        $group->users()->attach($user);

        $akses = app(HakAkses::class);
        $this->assertTrue($akses->allows($user, MenuKey::TaxCodes, Hak::View));
        $this->assertTrue($akses->allows($user, MenuKey::TaxCodes, Hak::Create));
        $this->assertFalse($akses->allows($user, MenuKey::TaxCodes, Hak::Delete));
        $this->assertFalse($akses->allows($user, MenuKey::Currencies, Hak::View));
        $this->assertTrue($akses->allowsSpecial($user, HakKhusus::SeeCost));
        $this->assertFalse($akses->allowsSpecial($user, HakKhusus::OpenClosedPeriod));

        $user->rightOverrides()->create(['menu_key' => MenuKey::TaxCodes->value, 'right' => 'view', 'allowed' => false]);
        $user->rightOverrides()->create(['menu_key' => MenuKey::Currencies->value, 'right' => 'print', 'allowed' => true]);
        $akses->forget($user);

        $this->assertFalse($akses->allows($user, MenuKey::TaxCodes, Hak::View), 'a deny override beats the group');
        $this->assertTrue($akses->allows($user, MenuKey::Currencies, Hak::Print), 'an allow override adds to the group');
    }

    public function test_an_inactive_account_loses_everything(): void
    {
        $admin = User::factory()->create(['access_type' => 'administrator', 'is_active' => false]);

        $this->assertFalse(app(HakAkses::class)->allows($admin, MenuKey::TaxCodes, Hak::View));
    }

    public function test_the_gate_answers_model_abilities_from_the_matrix(): void
    {
        $user = User::factory()->create();
        $group = AccessGroup::query()->create(['name' => 'Tax clerks']);
        $group->syncRights([MenuKey::TaxCodes->value => ['view', 'update']]);
        $group->users()->attach($user);
        $this->actingAs($user);

        $code = TaxCode::query()->create(['tax_type' => 'vat', 'description' => 'VAT', 'rate_percent' => 12]);

        $this->assertTrue(Gate::allows('update', $code));
        $this->assertTrue(Gate::allows('viewAny', TaxCode::class));
        $this->assertFalse(Gate::allows('delete', $code));
        $this->assertFalse(Gate::allows('create', TaxCode::class));
    }

    public function test_copying_rights_reproduces_a_group(): void
    {
        $source = AccessGroup::query()->create(['name' => 'Source']);
        $source->syncRights([MenuKey::TaxCodes->value => ['view', 'print']]);
        $source->syncSpecialRights(['see_cost', 'export_data']);
        $target = AccessGroup::query()->create(['name' => 'Target']);

        $target->copyRightsFrom($source);

        $this->assertSame($source->rightsMatrix(), $target->fresh()->load('rights')->rightsMatrix());
        $this->assertEqualsCanonicalizing(['see_cost', 'export_data'], $target->specialRights()->pluck('right')->all());
    }

    public function test_the_seeded_groups_divide_the_work_the_way_a_trading_company_does(): void
    {
        $this->seed(AccessGroupSeeder::class);
        $this->assertEqualsCanonicalizing(AccessGroupSeeder::GROUPS, AccessGroup::query()->pluck('name')->all());
        $akses = app(HakAkses::class);
        $member = function (string $group): User {
            $user = User::factory()->create();
            AccessGroup::query()->where('name', $group)->firstOrFail()->users()->attach($user);

            return $user;
        };

        $sales = $member('Sales');
        $this->assertTrue($akses->allows($sales, MenuKey::SalesOrders, Hak::Create));
        $this->assertTrue($akses->allows($sales, MenuKey::Customers, Hak::Update));
        $this->assertFalse($akses->allows($sales, MenuKey::SalesReceipts, Hak::Create), 'sales does not record money');
        $this->assertFalse($akses->allowsSpecial($sales, HakKhusus::SeeCost), 'sales does not see cost');
        $this->assertFalse($akses->allowsSpecial($sales, HakKhusus::ApproveTransactions), 'sales does not approve its own orders');

        $finance = $member('Finance');
        $this->assertTrue($akses->allows($finance, MenuKey::SalesReceipts, Hak::Create));
        $this->assertTrue($akses->allows($finance, MenuKey::BankReconciliation, Hak::Update));
        $this->assertTrue($akses->allowsSpecial($finance, HakKhusus::ApproveTransactions));
        $this->assertTrue($akses->allowsSpecial($finance, HakKhusus::SeeCreditData));
        $this->assertFalse($akses->allows($finance, MenuKey::PriceAndDiscountAdjustments, Hak::Create), 'finance does not set prices');
        $this->assertFalse($akses->allows($finance, MenuKey::SalesOrders, Hak::Create), 'finance reads orders, does not enter them');

        $accounting = $member('Accounting');
        $this->assertTrue($akses->allows($accounting, MenuKey::JournalVouchers, Hak::Create));
        $this->assertTrue($akses->allows($accounting, MenuKey::FixedAssets, Hak::Create));
        $this->assertTrue($akses->allowsSpecial($accounting, HakKhusus::OpenClosedPeriod));
        $this->assertFalse($akses->allows($accounting, MenuKey::SalesInvoices, Hak::Create), 'accounting reads the trade documents');
        $this->assertFalse($akses->allowsSpecial($accounting, HakKhusus::SeeCreditData));

        $purchasing = $member('Purchasing');
        $this->assertTrue($akses->allows($purchasing, MenuKey::PurchaseOrders, Hak::Create));
        $this->assertTrue($akses->allowsSpecial($purchasing, HakKhusus::SeeCost));
        $this->assertFalse($akses->allows($purchasing, MenuKey::GoodsReceipts, Hak::Create), 'the warehouse receives the goods');
        $this->assertFalse($akses->allows($purchasing, MenuKey::PurchasePayments, Hak::Create), 'finance pays');

        $warehouse = $member('Warehouse');
        $this->assertTrue($akses->allows($warehouse, MenuKey::DeliveryOrders, Hak::Update));
        $this->assertTrue($akses->allows($warehouse, MenuKey::GoodsReceipts, Hak::Create));
        $this->assertTrue($akses->allows($warehouse, MenuKey::ItemsAndServices, Hak::Update));
        $this->assertTrue($akses->allowsSpecial($warehouse, HakKhusus::ApproveTransactions), 'approves stock counts');
        $this->assertFalse($akses->allows($warehouse, MenuKey::SalesOrders, Hak::Create));
        $this->assertFalse($akses->allowsSpecial($warehouse, HakKhusus::SeeCreditData));

        $operator = $member('Administrator');
        $this->assertTrue($akses->allows($operator, MenuKey::Preferences, Hak::Update));
        $this->assertTrue($akses->allowsSpecial($operator, HakKhusus::DeletePostedTransactions));
    }
}
