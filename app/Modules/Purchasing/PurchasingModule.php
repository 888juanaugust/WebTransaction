<?php

declare(strict_types=1);

namespace App\Modules\Purchasing;

use App\Domain\Access\MenuKey;
use App\Domain\Approval\ApprovalType;
use App\Domain\Numbering\TransactionType;
use App\Domain\Purchasing\LastPurchasePrice;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\GoodsReceiptLine;
use App\Models\Purchasing\PaymentOrder;
use App\Models\Purchasing\PurchaseDownPayment;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseInvoiceLine;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderLine;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\PurchaseRequisition;
use App\Models\Purchasing\PurchaseRequisitionLine;
use App\Models\Purchasing\PurchaseReturn;
use App\Models\Purchasing\PurchaseReturnLine;
use App\Models\Purchasing\Vendor;
use App\Models\Purchasing\VendorCategory;
use App\Models\Purchasing\VendorClaim;
use App\Models\Purchasing\VendorPrice;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;

/** Purchasing: requisition to payment, vendors and their prices. Core. */
final class PurchasingModule extends BaseModule
{
    public static function key(): string
    {
        return 'purchasing';
    }

    public static function menuKeys(): array
    {
        return [
            MenuKey::PurchaseRequisitions, MenuKey::PurchaseOrders, MenuKey::GoodsReceipts, MenuKey::PurchaseDownPayments, MenuKey::PurchaseInvoices,
            MenuKey::PurchasePayments, MenuKey::PurchaseReturns, MenuKey::VendorClaims, MenuKey::VendorPrices, MenuKey::VendorCategories,
            MenuKey::Vendors, MenuKey::PaymentOrders, MenuKey::VendorTransfers,
        ];
    }

    public static function morphMap(): array
    {
        return [
            'vendor' => Vendor::class,
            'vendor_category' => VendorCategory::class,
            'purchase_requisition' => PurchaseRequisition::class,
            'purchase_requisition_line' => PurchaseRequisitionLine::class,
            'vendor_price' => VendorPrice::class,
            'purchase_order' => PurchaseOrder::class,
            'purchase_order_line' => PurchaseOrderLine::class,
            'goods_receipt' => GoodsReceipt::class,
            'goods_receipt_line' => GoodsReceiptLine::class,
            'purchase_down_payment' => PurchaseDownPayment::class,
            'purchase_invoice' => PurchaseInvoice::class,
            'purchase_invoice_line' => PurchaseInvoiceLine::class,
            'purchase_payment' => PurchasePayment::class,
            'purchase_return' => PurchaseReturn::class,
            'purchase_return_line' => PurchaseReturnLine::class,
            'vendor_claim' => VendorClaim::class,
            'payment_order' => PaymentOrder::class,
        ];
    }

    public static function boot(ModuleContext $context): void
    {
        $context->fulfilment->register(PurchaseRequisitionLine::class, PurchaseOrderLine::class);
        $context->fulfilment->register(PurchaseOrderLine::class, GoodsReceiptLine::class);
        $context->fulfilment->register(PurchaseOrderLine::class, PurchaseInvoiceLine::class);
        $context->fulfilment->register(GoodsReceiptLine::class, PurchaseInvoiceLine::class);

        // A purchase invoice keeps the item's last purchase price, when Preferences say so.
        $context->postings->extend(fn ($posting) => $context->app->make(LastPurchasePrice::class)->afterPosting($posting));
        $context->postings->onUnpost(fn ($posting) => $context->app->make(LastPurchasePrice::class)->afterUnposting($posting));

        // The documents that may wait for approval, under the transaction type approval rules name them by.
        $context->approvals->register(new ApprovalType(PurchaseRequisition::class, TransactionType::PurchaseRequisition));
        $context->approvals->register(new ApprovalType(PurchaseOrder::class, TransactionType::PurchaseOrder));
        $context->approvals->register(new ApprovalType(GoodsReceipt::class, TransactionType::GoodsReceipt));
        $context->approvals->register(new ApprovalType(PurchaseInvoice::class, TransactionType::PurchaseInvoice));
        $context->approvals->register(new ApprovalType(PurchaseReturn::class, TransactionType::PurchaseReturn));
        $context->approvals->register(new ApprovalType(VendorClaim::class, TransactionType::VendorClaim));
        $context->approvals->register(new ApprovalType(PurchasePayment::class, TransactionType::CashBankVoucher));
    }
}
