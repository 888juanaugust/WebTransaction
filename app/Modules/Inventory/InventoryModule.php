<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Domain\Access\MenuKey;
use App\Domain\Approval\ApprovalType;
use App\Domain\Inventory\OpnameApprover;
use App\Domain\Inventory\StockLedger;
use App\Domain\Numbering\TransactionType;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemBrand;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\ItemTransfer;
use App\Models\Inventory\ItemTransferLine;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockOpnameOrder;
use App\Models\Inventory\StockOpnameResult;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;

/** Inventory: items, warehouses, the stock ledger and its documents. Core. */
final class InventoryModule extends BaseModule
{
    public static function key(): string
    {
        return 'inventory';
    }

    public static function menuKeys(): array
    {
        return [
            MenuKey::ItemTransfers, MenuKey::InventoryAdjustments, MenuKey::StockOpnameOrders, MenuKey::StockOpnameResults, MenuKey::ItemsAndServices,
            MenuKey::Warehouses, MenuKey::Units, MenuKey::ItemCategories, MenuKey::ItemBrands, MenuKey::OrderFulfilment, MenuKey::StockByWarehouse, MenuKey::MinimumStock,
        ];
    }

    public static function morphMap(): array
    {
        return [
            'item' => Item::class,
            'item_category' => ItemCategory::class,
            'item_brand' => ItemBrand::class,
            'unit' => Unit::class,
            'warehouse' => Warehouse::class,
            'stock_movement' => StockMovement::class,
            'inventory_adjustment' => InventoryAdjustment::class,
            'item_transfer' => ItemTransfer::class,
            'item_transfer_line' => ItemTransferLine::class,
            'stock_opname_order' => StockOpnameOrder::class,
            'stock_opname_result' => StockOpnameResult::class,
        ];
    }

    public static function boot(ModuleContext $context): void
    {
        // The stock ledger writes the movements every posting declares.
        $context->postings->extend(fn ($posting, $builder) => $context->app->make(StockLedger::class)->write($posting, $builder));
        $context->postings->onUnpost(fn ($posting) => $context->app->make(StockLedger::class)->unwrite($posting));

        // The documents that may wait for approval, under the transaction type approval rules name them by.
        $context->approvals->register(new ApprovalType(InventoryAdjustment::class, TransactionType::InventoryAdjustment));
        $context->approvals->register(new ApprovalType(ItemTransfer::class, TransactionType::ItemTransfer));
        $context->approvals->register(OpnameApprover::type());

        // A received transfer's lines take from the sent transfer's: its processed quantities follow them.
        $context->fulfilment->register(ItemTransferLine::class, ItemTransferLine::class);
    }
}
