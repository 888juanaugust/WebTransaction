<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

use App\Domain\Shared\Format;
use App\Domain\Shared\Locales;

/**
 * Every field of the Preferences screen, one case each: its tab, its type, its
 * default (what a trading company usually wants; see docs/standard/settings.md)
 * and its English label. The value is the key stored in the preferences table.
 */
enum PreferensiKey: string
{
    // Company
    case CompanyName = 'company.name';
    case CompanyPhone = 'company.phone';
    case CompanyFax = 'company.fax';
    case CompanyEmail = 'company.email';
    case CompanyAddress = 'company.address';
    case DataStartDate = 'company.data_start_date';
    case FiscalYearStartMonth = 'company.fiscal_year_start_month';

    // Features
    case MultiBranch = 'features.multi_branch';
    case MultiCurrency = 'features.multi_currency';
    case Tax = 'features.tax';
    case Approval = 'features.approval';
    case FixedAssets = 'features.fixed_assets';
    case BudgetTarget = 'features.budget_target';
    case Department = 'features.department';
    case Project = 'features.project';
    case FinancialCategory = 'features.financial_category';
    case EmployeeLoan = 'features.employee_loan';
    case SalesExtras = 'features.sales_extras';
    case Payroll = 'features.payroll';

    // Tax
    case TaxCompanyName = 'tax.company_name';
    case PkpDate = 'tax.pkp_date';
    case PkpNumber = 'tax.pkp_number';
    case BusinessType = 'tax.business_type';
    case CompanyNpwp = 'tax.npwp';
    case Klu = 'tax.klu';
    case Nitku = 'tax.nitku';

    // Sales
    case CogsSource = 'sales.cogs_source';
    case ReturnCostCharge = 'sales.return_cost_charge';
    case ReturnCostAccount = 'sales.return_cost_account';
    case UpdateCostOnReturnResave = 'sales.update_item_cost_on_return_resave';
    case NewCustomerInclusiveTax = 'sales.new_customer_inclusive_tax';

    // Purchasing
    case LastPriceUpdatedByBill = 'purchasing.last_price_updated_by_bill';
    case LastPriceCutoffDate = 'purchasing.last_price_cutoff_date';
    case TemporaryPaymentAccount = 'purchasing.temporary_payment_account';

    // Restrictions
    case AccessRestriction = 'restrictions.mode';
    case AccessFrom = 'restrictions.from';
    case AccessUntil = 'restrictions.until';
    case AdministratorTwoFactor = 'restrictions.administrator_two_factor';

    // Attachments
    case AttachSalesQuotation = 'attachments.sales_quotation';
    case AttachSalesOrder = 'attachments.sales_order';
    case AttachDeliveryOrder = 'attachments.delivery_order';
    case AttachSalesInvoice = 'attachments.sales_invoice';
    case AttachSalesReceipt = 'attachments.sales_receipt';
    case AttachSalesReturn = 'attachments.sales_return';
    case AttachInvoiceExchange = 'attachments.invoice_exchange';
    case AttachCustomer = 'attachments.customer';
    case AttachPriceAdjustment = 'attachments.price_adjustment';

    // Extra attributes
    case TransactionExtraColumns = 'extra.transaction_columns';
    case ItemExtraColumns = 'extra.item_columns';
    case ExtraDateColumns = 'extra.date_columns';

    // Default accounts
    case ReceivableAccount = 'accounts.receivable';
    case CustomerDownPaymentAccount = 'accounts.customer_down_payment';
    case SalesDiscountAccount = 'accounts.sales_discount';
    case PayableAccount = 'accounts.payable';
    case VendorDownPaymentAccount = 'accounts.vendor_down_payment';
    case CostOfSalesAccount = 'accounts.cost_of_sales';
    case InventoryAccount = 'accounts.inventory';
    case GoodsInTransitAccount = 'accounts.goods_in_transit';
    case RoundingAccount = 'accounts.rounding';
    case GiroReceivableAccount = 'accounts.giro_receivable';
    case GiroPayableAccount = 'accounts.giro_payable';
    case ExchangeGainAccount = 'accounts.exchange_gain';
    case ExchangeLossAccount = 'accounts.exchange_loss';
    case SalaryExpenseAccount = 'accounts.salary_expense';
    case SalaryPayableAccount = 'accounts.salary_payable';
    case Pph21PayableAccount = 'accounts.pph21_payable';
    case BpjsPayableAccount = 'accounts.bpjs_payable';
    case BpjsExpenseAccount = 'accounts.bpjs_expense';

    // Other
    case Language = 'other.language';
    case DecimalFormat = 'other.decimal_format';
    case QuantityDecimals = 'other.quantity_decimals';
    case PriceDecimals = 'other.price_decimals';
    case DateFormat = 'other.date_format';
    case AgingRangeDays = 'other.aging_range_days';
    case AgingBasis = 'other.aging_basis';
    case AgingIntervalDays = 'other.aging_interval_days';
    case CommissionBasis = 'other.commission_basis';

    // Business rules (switches and day counts; every change is audited)
    case SalesOrderApproval = 'rules.sales_order_approval';
    case SegregationOfDuties = 'rules.segregation_of_duties';
    case AllowNegativeStock = 'rules.allow_negative_stock';
    case CreditNoticeDays = 'rules.credit_notice_days';
    case CreditFreezeDays = 'rules.credit_freeze_days';

    public function tab(): PreferensiTab
    {
        return PreferensiTab::from(explode('.', $this->value, 2)[0]);
    }

    /**
     * Whether the Preferences screen offers the key. Keys no part of this
     * release uses (attachments, extra columns, financial categories,
     * employee loans, the temporary payment account) are hidden; a value
     * already stored is kept.
     */
    public function isOffered(): bool
    {
        return ! in_array($this->tab(), [PreferensiTab::Attachments, PreferensiTab::ExtraAttributes], true)
            && ! in_array($this, [self::FinancialCategory, self::EmployeeLoan, self::TemporaryPaymentAccount], true);
    }

    public function type(): PreferensiType
    {
        return match ($this) {
            self::DataStartDate, self::PkpDate, self::LastPriceCutoffDate => PreferensiType::Date,
            self::AccessFrom, self::AccessUntil => PreferensiType::Time,
            self::FiscalYearStartMonth, self::CogsSource, self::ReturnCostCharge, self::AccessRestriction,
            self::Language, self::DecimalFormat, self::QuantityDecimals, self::PriceDecimals, self::DateFormat,
            self::AgingBasis, self::CommissionBasis => PreferensiType::Select,
            self::ReturnCostAccount, self::TemporaryPaymentAccount, self::ReceivableAccount,
            self::CustomerDownPaymentAccount, self::SalesDiscountAccount, self::PayableAccount,
            self::VendorDownPaymentAccount, self::CostOfSalesAccount, self::InventoryAccount,
            self::GoodsInTransitAccount, self::RoundingAccount, self::GiroReceivableAccount, self::GiroPayableAccount, self::ExchangeGainAccount, self::ExchangeLossAccount,
            self::SalaryExpenseAccount, self::SalaryPayableAccount, self::Pph21PayableAccount, self::BpjsPayableAccount, self::BpjsExpenseAccount => PreferensiType::Account,
            self::AgingRangeDays, self::AgingIntervalDays, self::CreditNoticeDays, self::CreditFreezeDays => PreferensiType::Int,
            self::AdministratorTwoFactor => PreferensiType::Bool,
            self::TransactionExtraColumns, self::ItemExtraColumns, self::ExtraDateColumns => PreferensiType::TextList,
            default => match ($this->tab()) {
                PreferensiTab::Features, PreferensiTab::Attachments, PreferensiTab::Rules => PreferensiType::Bool,
                PreferensiTab::Sales, PreferensiTab::Purchasing => PreferensiType::Bool,
                default => PreferensiType::Text,
            },
        };
    }

    public function default(): mixed
    {
        return match ($this) {
            self::FiscalYearStartMonth => '1',
            self::MultiBranch, self::MultiCurrency, self::Tax, self::Approval, self::FixedAssets, self::BudgetTarget => true,
            self::Department, self::Project, self::FinancialCategory, self::EmployeeLoan => false,
            self::CogsSource => 'sales_invoice_cogs',
            self::ReturnCostCharge => 'item_cogs_account',
            self::UpdateCostOnReturnResave, self::NewCustomerInclusiveTax => true,
            self::LastPriceUpdatedByBill => true,
            self::AccessRestriction => 'none',
            self::AccessFrom => '08:00',
            self::AccessUntil => '17:00',
            self::TransactionExtraColumns => array_fill(0, 15, null),
            self::ItemExtraColumns => array_fill(0, 10, null),
            self::ExtraDateColumns => array_fill(0, 2, null),
            self::Language => 'en',
            self::DecimalFormat => 'id',
            self::QuantityDecimals => '4',
            self::PriceDecimals => '0',
            self::DateFormat => 'd/m/Y',
            self::AgingRangeDays => 90,
            self::AgingBasis => 'invoice_date',
            self::AgingIntervalDays => 30,
            self::CommissionBasis => 'payment',
            self::SegregationOfDuties => true,
            self::AdministratorTwoFactor => false,
            self::SalesOrderApproval, self::AllowNegativeStock => false,
            self::CreditNoticeDays, self::CreditFreezeDays => 0,
            default => match ($this->type()) {
                PreferensiType::Bool => false,
                default => null,
            },
        };
    }

    /** @return array<string, string> options of a Select preference */
    public function options(): array
    {
        return match ($this) {
            self::FiscalYearStartMonth => collect(Format::months())->mapWithKeys(fn (string $name, int $m) => [(string) $m => $name])->all(),
            self::CogsSource => ['last_purchase_cost' => __('Last purchase price / landed cost'), 'sales_invoice_cogs' => __('Cost of sales on the sales invoice')],
            self::ReturnCostCharge => ['item_cogs_account' => "Charge to the item's cost of sales account", 'account' => __('Charge to a fixed account')],
            self::AccessRestriction => ['none' => __('Not restricted'), 'all' => __('Restricted for everyone'), 'time_window' => __('Access only within a time window')],
            self::Language => Locales::names(),
            self::DecimalFormat => ['id' => '1.234.567,89', 'en' => '1,234,567.89'],
            self::QuantityDecimals, self::PriceDecimals => ['0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4'],
            self::DateFormat => ['d/m/Y' => '17/10/2026', 'd-m-Y' => '17-10-2026', 'Y-m-d' => '2026-10-17'],
            self::AgingBasis => ['invoice_date' => __('Invoice date'), 'due_date' => __('Due date')],
            self::CommissionBasis => ['invoice' => __('Invoiced amount'), 'payment' => __('Amount actually paid')],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CompanyName => __('Company name'),
            self::CompanyPhone => __('Phone'),
            self::CompanyFax => __('Fax'),
            self::CompanyEmail => __('Email'),
            self::CompanyAddress => __('Address'),
            self::DataStartDate => __('Data start date'),
            self::FiscalYearStartMonth => __('Fiscal year starts in'),
            self::MultiBranch => __('Multiple branches'),
            self::MultiCurrency => __('Multiple currencies'),
            self::Tax => __('Tax'),
            self::Approval => __('Transaction approval'),
            self::FixedAssets => __('Fixed assets'),
            self::BudgetTarget => __('Budgets and targets'),
            self::Department => __('Departments'),
            self::Project => __('Projects'),
            self::FinancialCategory => __('Financial categories'),
            self::EmployeeLoan => __('Employee loans'),
            self::SalesExtras => __('Sales extras: check-ins, commissions, targets'),
            self::Payroll => __('Payroll entries and salary components'),
            self::TaxCompanyName => __('Registered company name'),
            self::PkpDate => __('VAT registration date'),
            self::PkpNumber => __('VAT registration number'),
            self::BusinessType => __('Business type'),
            self::CompanyNpwp => __('Company tax ID (NPWP)'),
            self::Klu => __('Business classification (KLU)'),
            self::Nitku => __('Business location ID (NITKU)'),
            self::CogsSource => __('Cost of returned goods taken from'),
            self::ReturnCostCharge => __('Sales return cost is charged'),
            self::ReturnCostAccount => __('Sales return cost account'),
            self::UpdateCostOnReturnResave => __('Update item cost when a sales return is saved again'),
            self::NewCustomerInclusiveTax => __('New customers default to prices including tax'),
            self::LastPriceUpdatedByBill => __('Last purchase price is updated by purchase invoices'),
            self::LastPriceCutoffDate => __('Only for invoices dated from'),
            self::TemporaryPaymentAccount => __('Temporary cash account for pending payments'),
            self::AccessRestriction => __('Access restriction'),
            self::AdministratorTwoFactor => __('Administrators sign in with a second factor'),
            self::AccessFrom => __('Access allowed from'),
            self::AccessUntil => __('Access allowed until'),
            self::AttachSalesQuotation => __('Sales quotations'),
            self::AttachSalesOrder => __('Sales orders'),
            self::AttachDeliveryOrder => __('Delivery orders'),
            self::AttachSalesInvoice => __('Sales invoices'),
            self::AttachSalesReceipt => __('Sales receipts'),
            self::AttachSalesReturn => __('Sales returns'),
            self::AttachInvoiceExchange => __('Invoice exchanges'),
            self::AttachCustomer => __('Customers'),
            self::AttachPriceAdjustment => __('Price and discount adjustments'),
            self::TransactionExtraColumns => __('Extra text columns on transactions'),
            self::ItemExtraColumns => __('Extra text columns on items'),
            self::ExtraDateColumns => __('Extra date columns'),
            self::ReceivableAccount => __('Accounts receivable'),
            self::CustomerDownPaymentAccount => __('Customer down payments'),
            self::SalesDiscountAccount => __('Sales discounts'),
            self::PayableAccount => __('Accounts payable'),
            self::VendorDownPaymentAccount => __('Vendor down payments'),
            self::CostOfSalesAccount => __('Cost of goods sold'),
            self::InventoryAccount => __('Inventory'),
            self::GoodsInTransitAccount => __('Goods delivered, not yet invoiced'),
            self::RoundingAccount => __('Rounding differences'),
            self::GiroReceivableAccount => __('Giros receivable (cheques received, not yet cleared)'),
            self::GiroPayableAccount => __('Giros payable (cheques issued, not yet cleared)'),
            self::ExchangeGainAccount => __('Realised exchange gains'),
            self::ExchangeLossAccount => __('Realised exchange losses'),
            self::SalaryExpenseAccount => __('Salaries (a component without its own account)'),
            self::SalaryPayableAccount => __('Net pay owed to employees'),
            self::Pph21PayableAccount => __('Income tax Art. 21 withheld'),
            self::BpjsPayableAccount => __('BPJS contributions owed'),
            self::BpjsExpenseAccount => __('BPJS contributions paid by the employer'),
            self::Language => __('Language'),
            self::DecimalFormat => __('Number format'),
            self::QuantityDecimals => __('Decimals on quantities'),
            self::PriceDecimals => __('Decimals on prices'),
            self::DateFormat => __('Date format'),
            self::AgingRangeDays => __('Aging range (days)'),
            self::AgingBasis => __('Age receivables from'),
            self::AgingIntervalDays => __('Aging interval (days)'),
            self::CommissionBasis => __('Commission is calculated from'),
            self::SalesOrderApproval => __('Sales orders wait for approval before they can be delivered or invoiced'),
            self::SegregationOfDuties => __('Segregation of duties: whoever enters a document never approves or verifies it'),
            self::AllowNegativeStock => __('Allow stock to go negative'),
            self::CreditNoticeDays => __('Flag a customer when an invoice is unpaid for more than (days)'),
            self::CreditFreezeDays => __('Freeze a customer when an invoice is unpaid for more than (days)'),
        };
    }

    public function help(): ?string
    {
        return match ($this) {
            self::CogsSource => __('The cost a returned item comes back at: the cost it left with on the invoice, or its last purchase price.'),
            self::AccessRestriction => __('Applies to every access group that follows these preferences.'),
            self::AdministratorTwoFactor => __('An administrator without an authenticator app set up sees only their profile page until they set one up.'),
            self::AgingRangeDays => __('Receivables older than this are reported as the last bucket.'),
            self::AllowNegativeStock => __('When off, a delivery or adjustment that would take stock below zero is refused.'),
            self::SegregationOfDuties => __('Turning this off is written to the activity log.'),
            self::SalesOrderApproval => __('Who approves is set under Settings → Transaction Approvers. Without a rule that covers the order, anyone with the "approve transactions" right may.'),
            self::CreditNoticeDays => __('The customer is flagged on sales documents and the invoice list. 0 switches this off.'),
            self::CreditFreezeDays => __('No order is approved for the customer until the aged invoice is settled. 0 switches this off.'),
            self::Language => __('The language of the screens and of documents sent to customers; each user may choose their own on their profile.'),
            self::DecimalFormat => __('How every number and amount reads and is typed.'),
            self::QuantityDecimals => __('Quantities show up to this many decimals; trailing zeros are dropped.'),
            self::PriceDecimals => __('Unit prices on printed documents show this many decimals.'),
            self::DateFormat => __('How dates are typed and shown in date fields; tables keep "17 Oct 2026".'),
            self::FixedAssets, self::BudgetTarget, self::Tax, self::Approval, self::SalesExtras, self::Payroll => __('Switches the module and its screens on or off; data already entered is kept.'),
            self::MultiBranch => __('Shows the Branches screen and branch filters; one default branch always exists.'),
            self::MultiCurrency => __('Shows the Currencies screen; with a foreign currency active, documents can be in it, kept in both currencies.'),
            self::Department => __('Shows the Departments screen; documents carry a department on the header and per line, and the income statement and ledger reports filter by it.'),
            self::Project => __('Shows the Projects screen; documents carry a project on the header and per line, and the income statement and ledger reports filter by it.'),
            self::LastPriceUpdatedByBill => __('A purchase invoice sets the item\'s purchase price to what it paid per base unit, when it is the item\'s latest invoice.'),
            self::DataStartDate => __('Nothing can be dated before it; opening balances default to it.'),
            self::FiscalYearStartMonth => __('Splits retained earnings from this year\'s income on the balance sheet, and starts year-to-date reports and new targets.'),
            self::UpdateCostOnReturnResave => __('When off, saving a return again keeps the cost its goods first came back at.'),
            default => null,
        };
    }
}
