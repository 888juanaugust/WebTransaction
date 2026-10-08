<?php

namespace Database\Seeders\Demo;

use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Enums\PtkpStatus;
use App\Domain\Shared\Enums\WorkStatus;
use App\Models\Company\Employee;
use App\Models\Company\PaymentTerm;
use App\Models\Company\SalaryComponent;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemBrand;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\CustomerCategory;
use App\Models\Sales\PriceCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A small trading company to try the screens with: brands, categories,
 * customers, vendors, items and an opening stock. Neutral names; nothing a
 * real client would keep. Seeded only on request (erp:install --demo or
 * --class), never by DatabaseSeeder. Runs again without duplicating.
 */
class DemoCompanySeeder extends Seeder
{
    public const OPENING_STOCK_NOTE = 'Demo opening stock';

    public function run(): void
    {
        $generator = app(NumberGenerator::class);
        $number = fn (TransactionType $type): string => $generator->next($generator->defaultSeries($type), CarbonImmutable::today());
        $account = fn (string $no): ?int => Account::query()->where('no', $no)->value('id');

        foreach (['Alpha', 'Beta', 'Gamma'] as $brand) {
            ItemBrand::query()->firstOrCreate(['name' => $brand]);
        }
        $general = ItemCategory::query()->where('is_default', true)->first();
        foreach (['Spare Parts', 'Consumables', 'Services'] as $category) {
            ItemCategory::query()->firstOrCreate(['name' => $category, 'parent_id' => null], [
                'inventory_account_id' => $general?->inventory_account_id ?? $account('1300'),
                'sales_account_id' => $general?->sales_account_id ?? $account('4100'),
                'cogs_account_id' => $general?->cogs_account_id ?? $account('5100'),
                'sales_return_account_id' => $general?->sales_return_account_id ?? $account('4200'),
                'purchase_return_account_id' => $general?->purchase_return_account_id ?? $account('1300'),
            ]);
        }
        foreach (['Retail', 'Wholesale', 'Distributor'] as $category) {
            CustomerCategory::query()->firstOrCreate(['name' => $category, 'parent_id' => null]);
        }

        $salespeople = [];
        foreach ([['Alex Doe', PtkpStatus::K1, 9_000_000], ['Sam Roe', PtkpStatus::TK0, 7_500_000]] as $i => [$name, $ptkp, $pay]) {
            $employee = Employee::query()->firstOrCreate(['name' => $name], ['number' => $number(TransactionType::Employee), 'is_salesman' => true, 'position' => 'Sales representative',
                'nik_no' => '317101010190000'.($i + 1), 'join_date' => CarbonImmutable::today()->startOfYear()->subYear()->toDateString(),
                'withhold_income_tax' => true, 'work_status' => WorkStatus::Permanent, 'tax_status' => $ptkp]);
            // With payroll on, a monthly salary to calculate payroll from.
            $salary = SalaryComponent::query()->where('fee_type', 'salary')->first();
            if ($salary !== null) {
                $employee->salaryComponents()->firstOrCreate(['salary_component_id' => $salary->id], ['amount' => $pay]);
            }
            $salespeople[] = $employee;
        }

        $priceCategory = PriceCategory::query()->where('is_default', true)->value('id');
        $terms = PaymentTerm::default()?->id;
        $categoryId = fn (string $name): ?int => CustomerCategory::query()->where('name', $name)->value('id');
        foreach ([
            ['Acme Trading', 'Wholesale', '12 Market Street', 'Jakarta', 0],
            ['Northwind Workshop', 'Retail', '4 Workshop Lane', 'Bandung', 1],
            ['Globex Distribution', 'Distributor', '88 Harbour Road', 'Surabaya', 0],
        ] as [$name, $category, $street, $city, $rep]) {
            Customer::query()->firstOrCreate(['name' => $name], [
                'number' => $number(TransactionType::Customer),
                'category_id' => $categoryId($category),
                'price_category_id' => $priceCategory,
                'payment_term_id' => $terms,
                'salesman_id' => $salespeople[$rep]->id,
                'bill_street' => $street,
                'bill_city' => $city,
                'ship_same_as_bill' => true,
                'default_inc_tax' => false,
                'is_active' => true,
            ]);
        }

        foreach ([['Contoso Supplies', '1 Supply Avenue', 'Jakarta'], ['Initech Parts', '20 Industrial Park', 'Bekasi']] as [$name, $street, $city]) {
            Vendor::query()->firstOrCreate(['name' => $name], [
                'number' => $number(TransactionType::Vendor),
                'payment_term_id' => $terms,
                'default_inc_tax' => false,
                'is_active' => true,
            ]);
        }

        $pcs = Unit::query()->where('name', 'PCS')->value('id');
        $vat = TaxCode::default()?->id;
        $brandId = fn (string $name): ?int => ItemBrand::query()->where('name', $name)->value('id');
        $itemCategoryId = fn (string $name): ?int => ItemCategory::query()->where('name', $name)->value('id');
        $items = [];
        foreach ([
            ['Widget A-100', 'Alpha', 'Spare Parts', 150_000, 100_000, 40],
            ['Widget B-200', 'Beta', 'Spare Parts', 275_000, 190_000, 24],
            ['Gasket set G-10', 'Gamma', 'Spare Parts', 45_000, 28_000, 120],
            ['Bearing 6204', 'Alpha', 'Spare Parts', 32_000, 21_000, 200],
            ['Lubricant 1 L', 'Beta', 'Consumables', 60_000, 42_000, 60],
            ['Filter F-3', 'Gamma', 'Consumables', 85_000, 55_000, 36],
        ] as [$name, $brand, $category, $sell, $buy, $qty]) {
            $item = Item::query()->firstOrCreate(['name' => $name], [
                'number' => $number(TransactionType::Item),
                'item_type' => 'inventory',
                'brand_id' => $brandId($brand),
                'category_id' => $itemCategoryId($category),
                'unit1_id' => $pcs,
                'sell_price' => $sell,
                'purchase_price' => $buy,
                'tax1_id' => $vat,
                'is_active' => true,
            ]);
            $items[] = [$item, $qty, $buy];
        }

        if (InventoryAdjustment::query()->where('description', self::OPENING_STOCK_NOTE)->exists()) {
            return;
        }
        $warehouse = Warehouse::default();
        $admin = User::query()->where('access_type', 'administrator')->orderBy('id')->first();
        $opening = InventoryAdjustment::query()->create([
            'number' => $number(TransactionType::InventoryAdjustment),
            'trans_date' => CarbonImmutable::today()->startOfMonth(),
            'description' => self::OPENING_STOCK_NOTE,
            'created_by' => $admin?->id,
        ]);
        foreach ($items as $sort => [$item, $qty, $cost]) {
            $opening->lines()->create(['sort' => $sort, 'item_id' => $item->id, 'adjustment_type' => 'quantity', 'quantity' => $qty, 'unit_id' => $pcs, 'base_quantity' => $qty, 'unit_cost' => $cost, 'total_cost' => 0, 'warehouse_id' => $warehouse?->id]);
        }
        app(DocumentRepository::class)->created($opening);
    }
}
