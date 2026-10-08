<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Domain\Access\MenuKey;
use App\Domain\Approval\ApprovalType;
use App\Domain\Numbering\TransactionType;
use App\Domain\Sales\OrderApproval;
use App\Models\Sales\Customer;
use App\Models\Sales\CustomerCategory;
use App\Models\Sales\Delivery;
use App\Models\Sales\DeliveryLine;
use App\Models\Sales\DiscountCategory;
use App\Models\Sales\InvoiceExchange;
use App\Models\Sales\InvoiceExchangeLine;
use App\Models\Sales\PriceCategory;
use App\Models\Sales\SalesDownPayment;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesInvoiceLine;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderLine;
use App\Models\Sales\SalesQuotation;
use App\Models\Sales\SalesQuotationLine;
use App\Models\Sales\SalesReceipt;
use App\Models\Sales\SalesReturn;
use App\Models\Sales\SalesReturnLine;
use App\Models\Sales\SellingPriceAdjustment;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;

/** Sales: quotation to receipt, customers and prices. Core. */
final class SalesModule extends BaseModule
{
    public static function key(): string
    {
        return 'sales';
    }

    public static function menuKeys(): array
    {
        return [
            MenuKey::SalesQuotations, MenuKey::SalesOrders, MenuKey::DeliveryOrders, MenuKey::SalesDownPayments, MenuKey::SalesInvoices,
            MenuKey::SalesReceipts, MenuKey::SalesReturns, MenuKey::InvoiceExchanges, MenuKey::CustomerCategories, MenuKey::PriceCategories,
            MenuKey::Customers, MenuKey::PriceAndDiscountAdjustments, MenuKey::ECommerceLinks,
        ];
    }

    public static function morphMap(): array
    {
        return [
            'customer' => Customer::class,
            'customer_category' => CustomerCategory::class,
            'price_category' => PriceCategory::class,
            'discount_category' => DiscountCategory::class,
            'sales_quotation' => SalesQuotation::class,
            'sales_quotation_line' => SalesQuotationLine::class,
            'sales_order' => SalesOrder::class,
            'sales_order_line' => SalesOrderLine::class,
            'delivery' => Delivery::class,
            'delivery_line' => DeliveryLine::class,
            'sales_down_payment' => SalesDownPayment::class,
            'sales_invoice' => SalesInvoice::class,
            'sales_invoice_line' => SalesInvoiceLine::class,
            'sales_receipt' => SalesReceipt::class,
            'sales_return' => SalesReturn::class,
            'sales_return_line' => SalesReturnLine::class,
            'invoice_exchange' => InvoiceExchange::class,
            'invoice_exchange_line' => InvoiceExchangeLine::class,
            'selling_price_adjustment' => SellingPriceAdjustment::class,
        ];
    }

    public static function boot(ModuleContext $context): void
    {
        // Who pulls lines from whom, so processed quantities and statuses follow.
        $context->fulfilment->register(SalesQuotationLine::class, SalesOrderLine::class);
        $context->fulfilment->register(SalesOrderLine::class, DeliveryLine::class);
        $context->fulfilment->register(SalesOrderLine::class, SalesInvoiceLine::class);
        $context->fulfilment->register(DeliveryLine::class, SalesInvoiceLine::class);

        // The documents that may wait for approval, under the transaction type approval rules name them by.
        $context->approvals->register(new ApprovalType(SalesQuotation::class, TransactionType::SalesQuotation));
        $context->approvals->register(new ApprovalType(Delivery::class, TransactionType::DeliveryOrder));
        $context->approvals->register(new ApprovalType(SalesInvoice::class, TransactionType::SalesInvoice));
        $context->approvals->register(new ApprovalType(SalesReturn::class, TransactionType::SalesReturn));
        $context->approvals->register(OrderApproval::type());
    }
}
