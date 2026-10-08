<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

use Filament\Support\Contracts\HasLabel;

/** The transaction types the Numbering screen offers, as the standard lists them. */
enum TransactionType: string implements HasLabel
{
    case FixedAsset = 'fixed_asset';
    case Item = 'item';
    case WithholdingSlip15 = 'withholding_slip_15';
    case WithholdingSlip21 = 'withholding_slip_21';
    case WithholdingSlip4_2 = 'withholding_slip_4_2';
    case WithholdingSlip23 = 'withholding_slip_23';
    case CheckIn = 'check_in';
    case FixedAssetDisposal = 'fixed_asset_disposal';
    case DraftTransaction = 'draft_transaction';
    case PurchaseInvoice = 'purchase_invoice';
    case SalesInvoice = 'sales_invoice';
    case VendorPrice = 'vendor_price';
    case StockOpnameResult = 'stock_opname_result';
    case JournalVoucher = 'journal_voucher';
    case Employee = 'employee';
    case VendorClaim = 'vendor_claim';
    case CashBankVoucher = 'cash_bank_voucher';
    case Customer = 'customer';
    case Vendor = 'vendor';
    case ItemTransfer = 'item_transfer';
    case SalesQuotation = 'sales_quotation';
    case ExpenseAccrual = 'expense_accrual';
    case PayrollEntry = 'payroll_entry';
    case GoodsReceipt = 'goods_receipt';
    case DeliveryOrder = 'delivery_order';
    case PriceAdjustment = 'price_adjustment';
    case InventoryAdjustment = 'inventory_adjustment';
    case PaymentOrder = 'payment_order';
    case StockOpnameOrder = 'stock_opname_order';
    case PurchaseRequisition = 'purchase_requisition';
    case FixedAssetChange = 'fixed_asset_change';
    case PurchaseOrder = 'purchase_order';
    case SalesOrder = 'sales_order';
    case AssetTransfer = 'asset_transfer';
    case PurchaseReturn = 'purchase_return';
    case SalesReturn = 'sales_return';
    case VatReturn = 'vat_return';
    case BudgetTransfer = 'budget_transfer';
    case BankTransfer = 'bank_transfer';
    case InvoiceExchange = 'invoice_exchange';

    public function getLabel(): string
    {
        return match ($this) {
            self::FixedAsset => __('Fixed Asset'),
            self::Item => __('Item & Service'),
            self::WithholdingSlip15 => __('Withholding Slip Art. 15'),
            self::WithholdingSlip21 => __('Withholding Slip Art. 21'),
            self::WithholdingSlip4_2 => __('Withholding Slip Art. 4(2)'),
            self::WithholdingSlip23 => __('Withholding Slip Art. 23'),
            self::CheckIn => __('Check-in'),
            self::FixedAssetDisposal => __('Fixed Asset Disposal'),
            self::DraftTransaction => __('Draft Transaction'),
            self::PurchaseInvoice => __('Purchase Invoice'),
            self::SalesInvoice => __('Sales Invoice'),
            self::VendorPrice => __('Vendor Price'),
            self::StockOpnameResult => __('Stock Opname Result'),
            self::JournalVoucher => __('Journal Voucher'),
            self::Employee => __('Employee'),
            self::VendorClaim => __('Vendor Claim'),
            self::CashBankVoucher => __('Cash / Bank Voucher'),
            self::Customer => __('Customer'),
            self::Vendor => __('Vendor'),
            self::ItemTransfer => __('Item Transfer'),
            self::SalesQuotation => __('Sales Quotation'),
            self::ExpenseAccrual => __('Expense Accrual'),
            self::PayrollEntry => __('Payroll Entry'),
            self::GoodsReceipt => __('Goods Receipt'),
            self::DeliveryOrder => __('Delivery Order'),
            self::PriceAdjustment => __('Price / Discount Adjustment'),
            self::InventoryAdjustment => __('Inventory Adjustment'),
            self::PaymentOrder => __('Payment Order'),
            self::StockOpnameOrder => __('Stock Opname Order'),
            self::PurchaseRequisition => __('Purchase Requisition'),
            self::FixedAssetChange => __('Fixed Asset Change'),
            self::PurchaseOrder => __('Purchase Order'),
            self::SalesOrder => __('Sales Order'),
            self::AssetTransfer => __('Asset Transfer'),
            self::PurchaseReturn => __('Purchase Return'),
            self::SalesReturn => __('Sales Return'),
            self::VatReturn => __('VAT Return'),
            self::BudgetTransfer => __('Budget Transfer'),
            self::BankTransfer => __('Bank Transfer'),
            self::InvoiceExchange => __('Invoice Exchange'),
        };
    }

    /** The prefix of the seeded default series; DESIGN.md names the first five. */
    public function defaultPrefix(): string
    {
        return match ($this) {
            self::SalesOrder => 'SO',
            self::PurchaseOrder => 'PO',
            self::SalesInvoice => 'INV',
            self::DeliveryOrder => 'DO',
            self::GoodsReceipt => 'GR',
            self::JournalVoucher => 'JV',
            self::InventoryAdjustment => 'ADJ',
            self::ItemTransfer => 'TRF',
            self::CashBankVoucher => 'CB',
            self::PurchaseRequisition => 'PR',
            self::PurchaseInvoice => 'BILL',
            self::SalesQuotation => 'SQ',
            self::SalesReturn => 'SR',
            self::PurchaseReturn => 'PRT',
            self::InvoiceExchange => 'IX',
            self::VendorClaim => 'VC',
            self::VendorPrice => 'VP',
            self::PaymentOrder => 'PYO',
            self::StockOpnameOrder => 'SOO',
            self::StockOpnameResult => 'SOR',
            self::PriceAdjustment => 'PA',
            self::ExpenseAccrual => 'EA',
            self::PayrollEntry => 'PAY',
            self::BankTransfer => 'BT',
            self::BudgetTransfer => 'BGT',
            self::CheckIn => 'CI',
            self::FixedAsset => 'FA',
            self::FixedAssetDisposal => 'FAD',
            self::FixedAssetChange => 'FAC',
            self::AssetTransfer => 'FAT',
            self::DraftTransaction => 'DRAFT',
            self::Item => 'ITM',
            self::Customer => 'C',
            self::Vendor => 'V',
            self::Employee => 'EMP',
            self::VatReturn => 'VAT',
            self::WithholdingSlip15 => 'WH15',
            self::WithholdingSlip21 => 'WH21',
            self::WithholdingSlip4_2 => 'WH42',
            self::WithholdingSlip23 => 'WH23',
        };
    }

    /** Masters number without a period: C-00001, not C-2610-0001. */
    public function isMaster(): bool
    {
        return in_array($this, [self::Item, self::Customer, self::Vendor, self::Employee, self::FixedAsset], true);
    }
}
