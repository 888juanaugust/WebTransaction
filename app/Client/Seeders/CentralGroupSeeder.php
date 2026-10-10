<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Client\Access\CentralGroups;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\Hak;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Access\ScreenKey;
use App\Filament\Modul;
use App\Models\Settings\AccessGroup;
use Illuminate\Database\Seeder;

/**
 * Central's roles: the rights of the six staff groups of CLAUDE.md's role
 * table, over the base's screens and Central's own. Sales never approves,
 * Finance never approves credit nor changes a price, Purchasing never sees
 * credit data, a Warehouse account sees only its own screens.
 *
 * Each role claims a group by its key (CentralGroups::claim): the base's
 * group of the same name the first time, so the owner keeps one list. The
 * seeder reshapes a group the first time it runs and leaves alone a group
 * shaped since (one already holding a right on a Central screen);
 * `central:reshape-groups` re-applies the matrix on purpose. Accounting
 * stays as the base shapes it. The group Central once called Inventory is
 * merged into Purchasing, its members kept.
 */
class CentralGroupSeeder extends Seeder
{
    public const ADMINISTRATOR = CentralGroups::ADMINISTRATOR;

    private const ALL = [Hak::View, Hak::Create, Hak::Update, Hak::Delete, Hak::Print];

    private const WORK = [Hak::View, Hak::Create, Hak::Update, Hak::Print];

    private const READ = [Hak::View, Hak::Print];

    /** Filing a claim: view, create and print; the Update right is the verifier's key and stays with Finance or Inventory. */
    private const FILE = [Hak::View, Hak::Create, Hak::Print];

    public function run(): void
    {
        $this->mergeLegacyInventory();
        foreach ($this->matrix() as $role => [$rights, $special]) {
            $group = CentralGroups::claim($role);
            if ($this->shapedByCentral($group)) {
                continue;
            }
            $this->apply($group, $rights, $special);
        }
    }

    /**
     * @param  array<string, list<string>>  $rights
     * @param  list<HakKhusus>  $special
     */
    public function apply(AccessGroup $group, array $rights, array $special): void
    {
        $group->syncRights($rights);
        $group->syncSpecialRights(array_map(fn (HakKhusus $r) => $r->value, $special));
    }

    /** The members of a Central 'Inventory' group (before purchasing joined it) move to Purchasing; the old group goes. */
    public function mergeLegacyInventory(): void
    {
        $old = AccessGroup::query()->whereNull('role_key')->where('name', CentralGroups::LEGACY_INVENTORY)->first();
        if ($old === null) {
            return;
        }
        $purchasing = CentralGroups::claim(CentralGroups::PURCHASING);
        $purchasing->users()->syncWithoutDetaching($old->users()->pluck('users.id')->all());
        $old->users()->detach();
        $old->rights()->delete();
        $old->specialRights()->delete();
        $old->delete();
    }

    /** @return array<string, array{0: array<string, list<string>>, 1: list<HakKhusus>}> */
    public function matrix(): array
    {
        $salesWork = [MenuKey::SalesQuotations, MenuKey::SalesOrders, MenuKey::CheckIns, MenuKey::Customers, CentralScreen::Collections];
        $salesFiles = [CentralScreen::SettlementClaims, CentralScreen::ExpenseClaims, CentralScreen::ReturnClaims];
        // The purchasing chain is Purchasing's; the money of it (bills, payments, down payments, payment orders) stays Finance's.
        $purchasingChain = [MenuKey::PurchaseOrders, MenuKey::GoodsReceipts, MenuKey::PurchaseReturns, MenuKey::VendorClaims, MenuKey::VendorPrices, MenuKey::VendorCategories, MenuKey::Vendors, MenuKey::VendorTransfers];
        $salesRead = [MenuKey::DeliveryOrders, MenuKey::SalesInvoices, MenuKey::SalesReceipts, MenuKey::SalesReturns, MenuKey::ItemsAndServices, MenuKey::StockByWarehouse, MenuKey::OrderFulfilment, MenuKey::PriceCategories, MenuKey::SalesTargets, MenuKey::SalesmanCommissions, CentralScreen::PriceList, CentralScreen::CustomerPrices, CentralScreen::CustomerTypes, CentralScreen::BuyerAccounts, CentralScreen::ProductAnalytics, MenuKey::Calendar, MenuKey::Contacts];

        return [
            self::ADMINISTRATOR => [
                $this->grant([...MenuKey::cases(), ...CentralScreen::cases()], self::ALL),
                HakKhusus::cases(),
            ],
            CentralGroups::SALES => [
                $this->grant($salesWork, self::WORK) + $this->grant($salesFiles, self::FILE) + $this->grant($salesRead, self::READ),
                [HakKhusus::SeeCreditData],
            ],
            CentralGroups::MARKETING => [
                $this->grant([MenuKey::SalesOrders, CentralScreen::BuyerAccounts, CentralScreen::SiteImages], self::ALL) + $this->grant([...$salesWork, CentralScreen::OrderApprovals, CentralScreen::DeliveryWatch], self::WORK) + $this->grant([CentralScreen::SettlementClaims], self::FILE) + $this->grant($salesRead, self::READ),
                [HakKhusus::SeeCreditData, HakKhusus::ApproveTransactions],
            ],
            CentralGroups::PURCHASING => [
                $this->grant([...$this->byModule(Modul::Inventory), ...$purchasingChain, CentralScreen::PriceList, CentralScreen::CustomerPrices, CentralScreen::Fulfilment, CentralScreen::StockAge, CentralScreen::ProductAnalytics, CentralScreen::CountSheets, CentralScreen::DamagedGoods], self::ALL)
                    + $this->grant([MenuKey::DeliveryOrders, MenuKey::SalesReturns, CentralScreen::ReturnClaims], self::WORK)
                    + $this->grant([MenuKey::SalesOrders], self::READ),
                [HakKhusus::SeeCost, HakKhusus::ApproveTransactions], // approves stock counts, transfers and purchases, never a sale
            ],
            CentralGroups::WAREHOUSE => [
                $this->grant([CentralScreen::Fulfilment, CentralScreen::CountSheets, MenuKey::DeliveryOrders], self::WORK) + $this->grant([MenuKey::StockByWarehouse, CentralScreen::StockAge], self::READ),
                [],
            ],
            CentralGroups::FINANCE => [
                $this->grant([...$this->byModule(Modul::CashBank, Modul::GeneralLedger, Modul::Tax, Modul::Reports, Modul::FixedAssets), MenuKey::MonthEndProcess, MenuKey::SalesReceipts, MenuKey::SalesInvoices, MenuKey::SalesDownPayments, MenuKey::InvoiceExchanges, MenuKey::PurchaseInvoices, MenuKey::PurchasePayments, MenuKey::PurchaseDownPayments, MenuKey::PaymentOrders, MenuKey::ExpenseAccruals, MenuKey::SalesTargets, MenuKey::SalesmanCommissions, CentralScreen::SettlementClaims, CentralScreen::ExpenseClaims, CentralScreen::Collections, CentralScreen::BuyerAccounts, CentralScreen::CustomerTypes], self::ALL)
                    + $this->grant([MenuKey::Customers], self::WORK)
                    + $this->grant([MenuKey::Vendors, MenuKey::SalesOrders, MenuKey::PurchaseOrders, MenuKey::DeliveryOrders, MenuKey::GoodsReceipts, MenuKey::SalesReturns, MenuKey::Calendar, MenuKey::Contacts, CentralScreen::Teams, CentralScreen::DeliveryWatch, CentralScreen::ProductAnalytics, CentralScreen::DamagedGoods, CentralScreen::YearEnd], self::READ),
                [HakKhusus::SeeCreditData, HakKhusus::OverrideCreditLimit, HakKhusus::ExportData],
            ],
        ];
    }

    /** A group holding a right on any Central screen has been through this seeder, or the owner's hands, already. */
    private function shapedByCentral(AccessGroup $group): bool
    {
        return $group->rights()->where('menu_key', 'like', 'client__%')->exists();
    }

    /** @return list<MenuKey> */
    private function byModule(Modul ...$moduls): array
    {
        return array_values(array_filter(MenuKey::cases(), fn (MenuKey $k) => in_array($k->modul(), $moduls, true)));
    }

    /**
     * @param  list<ScreenKey>  $keys
     * @param  list<Hak>  $rights
     * @return array<string, list<string>>
     */
    private function grant(array $keys, array $rights): array
    {
        $out = [];
        foreach ($keys as $key) {
            if ($key->isReplicated()) {
                $out[$key->value] = array_map(fn (Hak $h) => $h->value, $rights);
            }
        }

        return $out;
    }
}
