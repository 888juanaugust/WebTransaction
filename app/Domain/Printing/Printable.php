<?php

declare(strict_types=1);

namespace App\Domain\Printing;

use App\Domain\Numbering\TransactionType;
use App\Models\CashBank\BankTransfer;
use App\Models\CashBank\CashPayment;
use App\Models\CashBank\CashReceipt;
use App\Models\GeneralLedger\JournalVoucher;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\ItemTransfer;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\PurchaseReturn;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesQuotation;
use App\Models\Sales\SalesReceipt;
use App\Models\Sales\SalesReturn;
use Illuminate\Database\Eloquent\Model;

/**
 * Which documents print, under which title, in which shape. The morph alias
 * is the key in the print URL; the layout is the print layout of the
 * transaction type.
 */
final class Printable
{
    /** @return array<string, array{model: class-string<Model>, type: TransactionType, title: string, shape: string, party: ?string}> */
    public static function all(): array
    {
        return [
            'sales_quotation' => ['model' => SalesQuotation::class, 'type' => TransactionType::SalesQuotation, 'title' => __('Sales Quotation'), 'shape' => 'priced', 'party' => 'customer'],
            'sales_order' => ['model' => SalesOrder::class, 'type' => TransactionType::SalesOrder, 'title' => __('Sales Order'), 'shape' => 'priced', 'party' => 'customer'],
            'delivery' => ['model' => Delivery::class, 'type' => TransactionType::DeliveryOrder, 'title' => __('Delivery Order'), 'shape' => 'priced', 'party' => 'customer'],
            'sales_invoice' => ['model' => SalesInvoice::class, 'type' => TransactionType::SalesInvoice, 'title' => __('Invoice'), 'shape' => 'priced', 'party' => 'customer'],
            'sales_return' => ['model' => SalesReturn::class, 'type' => TransactionType::SalesReturn, 'title' => __('Sales Return'), 'shape' => 'priced', 'party' => 'customer'],
            'sales_receipt' => ['model' => SalesReceipt::class, 'type' => TransactionType::CashBankVoucher, 'title' => __('Receipt'), 'shape' => 'settlement', 'party' => 'customer'],
            'purchase_order' => ['model' => PurchaseOrder::class, 'type' => TransactionType::PurchaseOrder, 'title' => __('Purchase Order'), 'shape' => 'priced', 'party' => 'vendor'],
            'goods_receipt' => ['model' => GoodsReceipt::class, 'type' => TransactionType::GoodsReceipt, 'title' => __('Goods Receipt'), 'shape' => 'priced', 'party' => 'vendor'],
            'purchase_invoice' => ['model' => PurchaseInvoice::class, 'type' => TransactionType::PurchaseInvoice, 'title' => __('Purchase Invoice'), 'shape' => 'priced', 'party' => 'vendor'],
            'purchase_return' => ['model' => PurchaseReturn::class, 'type' => TransactionType::PurchaseReturn, 'title' => __('Purchase Return'), 'shape' => 'priced', 'party' => 'vendor'],
            'purchase_payment' => ['model' => PurchasePayment::class, 'type' => TransactionType::CashBankVoucher, 'title' => __('Payment Voucher'), 'shape' => 'settlement', 'party' => 'vendor'],
            'cash_payment' => ['model' => CashPayment::class, 'type' => TransactionType::CashBankVoucher, 'title' => __('Payment Voucher'), 'shape' => 'cash', 'party' => null],
            'cash_receipt' => ['model' => CashReceipt::class, 'type' => TransactionType::CashBankVoucher, 'title' => __('Receipt Voucher'), 'shape' => 'cash', 'party' => null],
            'bank_transfer' => ['model' => BankTransfer::class, 'type' => TransactionType::BankTransfer, 'title' => __('Bank Transfer'), 'shape' => 'transfer', 'party' => null],
            'journal_voucher' => ['model' => JournalVoucher::class, 'type' => TransactionType::JournalVoucher, 'title' => __('Journal Voucher'), 'shape' => 'journal', 'party' => null],
            'inventory_adjustment' => ['model' => InventoryAdjustment::class, 'type' => TransactionType::InventoryAdjustment, 'title' => __('Inventory Adjustment'), 'shape' => 'stock', 'party' => null],
            'item_transfer' => ['model' => ItemTransfer::class, 'type' => TransactionType::ItemTransfer, 'title' => __('Item Transfer'), 'shape' => 'stock', 'party' => null],
        ];
    }

    /** @return array{model: class-string<Model>, type: TransactionType, title: string, shape: string, party: ?string}|null */
    public static function for(string $alias): ?array
    {
        return self::all()[$alias] ?? null;
    }

    public static function aliasOf(Model $document): ?string
    {
        $alias = $document->getMorphClass();

        return isset(self::all()[$alias]) ? $alias : null;
    }
}
