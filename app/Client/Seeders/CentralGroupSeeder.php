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
 * Central's roles: the rights of the six groups of CLAUDE.md's role table,
 * over the base's screens and Central's own. Sales never approves, Finance
 * never approves credit nor changes a price, Inventory never sees credit
 * data, a Warehouse account sees only its own screens.
 *
 * The base seeds its own groups first; Central reshapes the ones that are
 * its roles the first time it runs, and leaves alone a group the owner has
 * shaped since (a group already holding a right on a Central screen).
 * Accounting and Purchasing stay as the base shapes them.
 */
class CentralGroupSeeder extends Seeder
{
    public const ADMINISTRATOR = 'Administrator';

    private const ALL = [Hak::View, Hak::Create, Hak::Update, Hak::Delete, Hak::Print];

    private const WORK = [Hak::View, Hak::Create, Hak::Update, Hak::Print];

    private const READ = [Hak::View, Hak::Print];

    /** Filing a claim: view, create and print; the Update right is the verifier's key and stays with Finance or Inventory. */
    private const FILE = [Hak::View, Hak::Create, Hak::Print];

    public function run(): void
    {
        foreach ($this->matrix() as $name => [$rights, $special]) {
            $group = AccessGroup::query()->firstOrCreate(['name' => $name], ['restriction_type' => 'preferences']);
            if ($this->shapedByCentral($group)) {
                continue;
            }
            $group->syncRights($rights);
            $group->syncSpecialRights(array_map(fn (HakKhusus $r) => $r->value, $special));
        }
    }

    /** @return array<string, array{0: array<string, list<string>>, 1: list<HakKhusus>}> */
    public function matrix(): array
    {
        $salesWork = [MenuKey::SalesQuotations, MenuKey::SalesOrders, MenuKey::CheckIns, MenuKey::Customers, CentralScreen::Collections];
        $salesFiles = [CentralScreen::SettlementClaims, CentralScreen::ExpenseClaims, CentralScreen::ReturnClaims];
        $salesRead = [MenuKey::DeliveryOrders, MenuKey::SalesInvoices, MenuKey::SalesReceipts, MenuKey::SalesReturns, MenuKey::ItemsAndServices, MenuKey::StockByWarehouse, MenuKey::OrderFulfilment, MenuKey::PriceCategories, MenuKey::SalesTargets, MenuKey::SalesmanCommissions, CentralScreen::PriceList, CentralScreen::CustomerPrices, CentralScreen::BuyerAccounts, MenuKey::Calendar, MenuKey::Contacts];

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
                $this->grant([MenuKey::SalesOrders, CentralScreen::BuyerAccounts], self::ALL) + $this->grant([...$salesWork, CentralScreen::OrderApprovals], self::WORK) + $this->grant([CentralScreen::SettlementClaims], self::FILE) + $this->grant($salesRead, self::READ),
                [HakKhusus::SeeCreditData, HakKhusus::ApproveTransactions],
            ],
            CentralGroups::INVENTORY => [
                $this->grant([...$this->byModule(Modul::Inventory), CentralScreen::PriceList, CentralScreen::CustomerPrices, CentralScreen::Fulfilment], self::ALL)
                    + $this->grant([MenuKey::DeliveryOrders, MenuKey::GoodsReceipts, MenuKey::SalesReturns, CentralScreen::ReturnClaims], self::WORK)
                    + $this->grant([MenuKey::SalesOrders, MenuKey::PurchaseOrders], self::READ),
                [HakKhusus::SeeCost, HakKhusus::ApproveTransactions], // approves stock counts and transfers, never a sale

            ],
            CentralGroups::WAREHOUSE => [
                $this->grant([CentralScreen::Fulfilment, MenuKey::DeliveryOrders], self::WORK) + $this->grant([MenuKey::StockByWarehouse], self::READ),
                [],
            ],
            CentralGroups::FINANCE => [
                $this->grant([...$this->byModule(Modul::CashBank, Modul::GeneralLedger, Modul::Tax, Modul::Reports), MenuKey::SalesReceipts, MenuKey::SalesInvoices, MenuKey::SalesDownPayments, MenuKey::InvoiceExchanges, MenuKey::PurchaseInvoices, MenuKey::PurchasePayments, MenuKey::PurchaseDownPayments, MenuKey::PaymentOrders, MenuKey::ExpenseAccruals, MenuKey::SalesTargets, MenuKey::SalesmanCommissions, CentralScreen::SettlementClaims, CentralScreen::ExpenseClaims, CentralScreen::Collections, CentralScreen::BuyerAccounts], self::ALL)
                    + $this->grant([MenuKey::Customers], self::WORK)
                    + $this->grant([MenuKey::Vendors, MenuKey::SalesOrders, MenuKey::PurchaseOrders, MenuKey::DeliveryOrders, MenuKey::GoodsReceipts, MenuKey::SalesReturns, MenuKey::Calendar, MenuKey::Contacts, CentralScreen::Teams], self::READ),
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
