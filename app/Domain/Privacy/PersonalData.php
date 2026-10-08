<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Audit\Auditor;
use App\Models\Company\AuditLog;
use App\Models\Company\Employee;
use App\Models\Company\PayrollEntryLine;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReceipt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Everything the system holds about one customer, vendor or employee, for a request under UU PDP 27/2022 (see
 * docs/PRIVACY.md): the record with its contacts, addresses and bank accounts, the documents made with them, and
 * when their record was changed and by whom. Credit data stays out for someone without the right to see it. Each
 * export is in the Activity Log.
 */
final class PersonalData
{
    private const CREDIT = ['credit_limit_mode', 'parent_customer_id', 'credit_limit_amount', 'credit_limit_amount_enabled', 'credit_limit_age_days', 'credit_limit_age_enabled'];

    /** @return array<string, mixed> */
    public static function of(Model $party): array
    {
        $record = collect($party->attributesToArray())->except(['created_at', 'updated_at']);
        if ($party instanceof Customer && ! HakAkses::canSpecial(HakKhusus::SeeCreditData)) {
            $record = $record->except(self::CREDIT);
        }
        $rows = fn ($relation) => $relation->get()->map(fn (Model $m) => collect($m->attributesToArray())->except(['id', 'created_at', 'updated_at'])->all())->all();
        $documents = fn ($query, array $columns) => $query->orderBy('trans_date')->get($columns)->map(fn (Model $m) => $m->only($columns))->all();

        $data = match (true) {
            $party instanceof Customer => [
                'contacts' => $rows($party->contacts()),
                'addresses' => $rows($party->addresses()),
                'invoices' => $documents(SalesInvoice::query()->where('customer_id', $party->id), ['number', 'trans_date', 'total', 'paid_amount']),
                'receipts' => $documents(SalesReceipt::query()->where('customer_id', $party->id), ['number', 'trans_date', 'amount']),
            ],
            $party instanceof Vendor => [
                'contacts' => $rows($party->contacts()),
                'bank_accounts' => $rows($party->bankAccounts()),
                'invoices' => $documents(PurchaseInvoice::query()->where('vendor_id', $party->id), ['number', 'trans_date', 'total', 'paid_amount']),
                'payments' => $documents(PurchasePayment::query()->where('vendor_id', $party->id), ['number', 'trans_date', 'amount']),
            ],
            $party instanceof Employee => [
                'pay_setup' => $rows($party->salaryComponents()),
                'payroll' => PayrollEntryLine::query()->where('employee_id', $party->id)->with('payrollEntry:id,number,period_year,period_month')->orderBy('id')->get()
                    ->map(fn (PayrollEntryLine $l) => ['entry' => $l->payrollEntry?->number, 'year' => $l->payrollEntry?->period_year, 'month' => $l->payrollEntry?->period_month,
                        'fee_type' => $l->fee_type, 'gross' => (int) $l->gross_amount, 'income_tax' => (int) $l->income_tax])->all(),
            ],
            default => throw new InvalidArgumentException('Not a customer, vendor or employee.'),
        };

        return [
            'kind' => $party->getMorphClass(),
            'exported_at' => now()->toIso8601String(),
            'record' => $record->all(),
            ...$data,
            // When the record was changed and by whom; what changed stays in the log.
            'changes' => AuditLog::query()->where('document_type', $party->getMorphClass())->where('document_id', $party->getKey())->with('user:id,name')->orderBy('id')->get()
                ->map(fn (AuditLog $log) => ['at' => $log->created_at?->toIso8601String(), 'action' => $log->action, 'by' => $log->user?->name])->all(),
        ];
    }

    /** The export as a JSON file's contents, logged. */
    public static function json(Model $party): string
    {
        $json = json_encode(self::of($party), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Auditor::log('personal_data_exported', $party);

        return $json;
    }
}
