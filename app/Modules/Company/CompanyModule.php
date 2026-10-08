<?php

declare(strict_types=1);

namespace App\Modules\Company;

use App\Console\Commands\PostOpeningBalancesCommand;
use App\Console\Commands\RecurringCommand;
use App\Domain\Access\MenuKey;
use App\Domain\Access\ScreenKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\AuditLog;
use App\Models\Company\Branch;
use App\Models\Company\CalendarEvent;
use App\Models\Company\Contact;
use App\Models\Company\Currency;
use App\Models\Company\CurrencyRate;
use App\Models\Company\Employee;
use App\Models\Company\EmployeeSalaryComponent;
use App\Models\Company\Fob;
use App\Models\Company\MemorizedTransaction;
use App\Models\Company\OpeningBalance;
use App\Models\Company\PaymentTerm;
use App\Models\Company\RecurringTransaction;
use App\Models\Company\Shipment;
use App\Models\Company\TaxCode;
use App\Modules\BaseModule;
use Illuminate\Console\Scheduling\Schedule;

/** Company: the masters every module shares, the calendar, recurring and memorized transactions, the activity log. Core. */
final class CompanyModule extends BaseModule
{
    public static function key(): string
    {
        return 'company';
    }

    public static function menuKeys(): array
    {
        return [
            MenuKey::Currencies, MenuKey::Branches, MenuKey::TaxCodes, MenuKey::PaymentTerms, MenuKey::ShippingMethods, MenuKey::FOBTerms,
            MenuKey::Employees, MenuKey::RecurringTransactions, MenuKey::MonthEndProcess, MenuKey::Contacts, MenuKey::MemorizedTransactions,
            MenuKey::Calendar, MenuKey::ActivityLog,
        ];
    }

    /** Branches and currencies show only when the company runs several. */
    public static function featureForKey(ScreenKey $key): ?PreferensiKey
    {
        return match ($key) {
            MenuKey::Branches => PreferensiKey::MultiBranch,
            MenuKey::Currencies => PreferensiKey::MultiCurrency,
            default => null,
        };
    }

    public static function morphMap(): array
    {
        return [
            'branch' => Branch::class,
            'currency' => Currency::class,
            'currency_rate' => CurrencyRate::class,
            'tax_code' => TaxCode::class,
            'payment_term' => PaymentTerm::class,
            'shipment' => Shipment::class,
            'fob' => Fob::class,
            'audit_log' => AuditLog::class,
            'employee' => Employee::class,
            'employee_salary_component' => EmployeeSalaryComponent::class,
            'contact' => Contact::class,
            'recurring_transaction' => RecurringTransaction::class,
            'memorized_transaction' => MemorizedTransaction::class,
            'calendar_event' => CalendarEvent::class,
            'opening_balance' => OpeningBalance::class,
        ];
    }

    public static function commands(): array
    {
        return [RecurringCommand::class, PostOpeningBalancesCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        // One run at a time, on one server; each schedule is also row-locked while it runs.
        $schedule->command('erp:recurring')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
    }
}
