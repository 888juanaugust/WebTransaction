<?php

namespace Database\Seeders\Defaults;

use App\Domain\Access\Hak;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Filament\Modul;
use App\Models\Settings\AccessGroup;
use Illuminate\Database\Seeder;

/**
 * Six starting groups shaped the way most trading companies divide the
 * work. Each gets rights per screen, including the screens of modules that
 * are switched off, so switching one on later needs no re-seed. The owner
 * adjusts them on the Access Groups screen; a group already shaped there
 * is left alone. "Administrator" is a group too, for operators who need
 * everything without the administrator account type.
 */
class AccessGroupSeeder extends Seeder
{
    public const GROUPS = ['Administrator', 'Accounting', 'Finance', 'Sales', 'Purchasing', 'Warehouse'];

    private const ALL = ['view', 'create', 'update', 'delete', 'print'];

    private const READ = ['view', 'print'];

    private const WORK = ['view', 'create', 'update', 'print'];

    public function run(): void
    {
        $byModule = fn (Modul ...$moduls) => array_values(array_filter(
            MenuKey::cases(),
            fn (MenuKey $k) => $k->isReplicated() && in_array($k->modul(), $moduls, true),
        ));

        $groups = [
            'Administrator' => [
                'rights' => $this->grant(MenuKey::cases(), self::ALL),
                'special' => HakKhusus::cases(),
            ],
            'Accounting' => [
                'rights' => $this->grant($byModule(Modul::GeneralLedger, Modul::CashBank, Modul::Tax, Modul::Reports, Modul::FixedAssets), self::ALL)
                    + $this->grant([MenuKey::Currencies, MenuKey::TaxCodes, MenuKey::PaymentTerms, MenuKey::Employees, MenuKey::SalaryComponents, MenuKey::MonthEndProcess, MenuKey::RecurringTransactions, MenuKey::MemorizedTransactions, MenuKey::Contacts, MenuKey::Calendar, MenuKey::ActivityLog, MenuKey::Departments, MenuKey::Projects], self::ALL)
                    + $this->grant($byModule(Modul::Sales, Modul::Purchasing), self::READ)
                    + $this->grant([MenuKey::ItemsAndServices, MenuKey::StockByWarehouse, MenuKey::InventoryAdjustments], self::READ),
                'special' => [HakKhusus::OpenClosedPeriod, HakKhusus::BackdateTransactions, HakKhusus::DeletePostedTransactions, HakKhusus::ExportData, HakKhusus::SeeCost],
            ],
            'Finance' => [
                'rights' => $this->grant($byModule(Modul::CashBank), self::ALL)
                    + $this->grant([MenuKey::SalesInvoices, MenuKey::SalesReceipts, MenuKey::SalesDownPayments, MenuKey::InvoiceExchanges, MenuKey::PurchaseInvoices, MenuKey::PurchasePayments, MenuKey::PurchaseDownPayments, MenuKey::PaymentOrders, MenuKey::ExpenseAccruals], self::WORK)
                    + $this->grant([MenuKey::Customers, MenuKey::Vendors, MenuKey::SalesOrders, MenuKey::PurchaseOrders, MenuKey::DeliveryOrders, MenuKey::GoodsReceipts, MenuKey::ReportCatalogue, MenuKey::AccountHistory, MenuKey::Calendar, MenuKey::Contacts, MenuKey::Departments, MenuKey::Projects], self::READ),
                'special' => [HakKhusus::SeeCreditData, HakKhusus::OverrideCreditLimit, HakKhusus::ApproveTransactions, HakKhusus::ExportData],
            ],
            'Sales' => [
                'rights' => $this->grant([MenuKey::SalesQuotations, MenuKey::SalesOrders, MenuKey::SalesReturns, MenuKey::Customers, MenuKey::CheckIns], self::WORK)
                    + $this->grant([MenuKey::DeliveryOrders, MenuKey::SalesInvoices, MenuKey::SalesReceipts, MenuKey::CustomerCategories, MenuKey::PriceCategories, MenuKey::ItemsAndServices, MenuKey::StockByWarehouse, MenuKey::OrderFulfilment, MenuKey::SalesTargets, MenuKey::SalesmanCommissions, MenuKey::Calendar, MenuKey::Contacts, MenuKey::Departments, MenuKey::Projects], self::READ),
                'special' => [],
            ],
            'Purchasing' => [
                'rights' => $this->grant([MenuKey::PurchaseRequisitions, MenuKey::PurchaseOrders, MenuKey::PurchaseReturns, MenuKey::VendorClaims, MenuKey::VendorPrices, MenuKey::Vendors], self::WORK)
                    + $this->grant([MenuKey::GoodsReceipts, MenuKey::PurchaseInvoices, MenuKey::VendorCategories, MenuKey::ItemsAndServices, MenuKey::StockByWarehouse, MenuKey::MinimumStock, MenuKey::Calendar, MenuKey::Contacts, MenuKey::Departments, MenuKey::Projects], self::READ),
                'special' => [HakKhusus::SeeCost],
            ],
            'Warehouse' => [
                'rights' => $this->grant($byModule(Modul::Inventory), self::ALL)
                    + $this->grant([MenuKey::DeliveryOrders, MenuKey::GoodsReceipts], self::WORK)
                    + $this->grant([MenuKey::SalesOrders, MenuKey::PurchaseOrders, MenuKey::PurchaseReturns, MenuKey::SalesReturns, MenuKey::Departments, MenuKey::Projects], self::READ),
                'special' => [HakKhusus::SeeCost, HakKhusus::ApproveTransactions],
            ],
        ];

        foreach ($groups as $name => $spec) {
            $group = AccessGroup::query()->firstOrCreate(['name' => $name], ['restriction_type' => 'preferences']);
            if ($group->rights()->exists()) {
                continue; // already shaped by the owner
            }
            $group->syncRights($spec['rights']);
            $group->syncSpecialRights(array_map(fn (HakKhusus $r) => $r->value, $spec['special']));
        }
    }

    /**
     * @param  list<MenuKey>  $keys
     * @param  list<string>  $rights
     * @return array<string, list<string>>
     */
    private function grant(array $keys, array $rights): array
    {
        $out = [];
        foreach ($keys as $key) {
            if ($key->isReplicated()) {
                $out[$key->value] = array_values(array_filter($rights, fn (string $r) => Hak::tryFrom($r) !== null));
            }
        }

        return $out;
    }
}
