<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Console\BirthdaysCommand;
use App\Client\Domain\Customers\Birthdays;
use App\Client\Domain\Customers\CustomerTypeTerms;
use App\Client\Models\CustomerType;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\CustomerTypeSeeder;
use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Company\CalendarFeed;
use App\Models\Sales\Customer;
use App\Models\Sales\CustomerContact;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use WeakMap;

/** Customer types and the terms they set on their customers. */
final class CustomersModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-customers';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::CustomerTypes];
    }

    public static function morphMap(): array
    {
        return ['customer_type' => CustomerType::class];
    }

    /** What a save changed through the type, kept until the customer is saved and the audit can name it. @var WeakMap<Customer, array<string, mixed>>|null */
    private static ?WeakMap $pending = null;

    public static function boot(ModuleContext $context): void
    {
        self::$pending ??= new WeakMap;
        // Birthdays of the people at a customer, for whoever may open Customers, within their branches.
        CalendarFeed::extend('birthday', fn () => __('Birthday'), 'bg-pink-50 text-pink-800', function (CarbonImmutable $from, CarbonImmutable $until, callable $add): void {
            $user = auth()->user();
            if ($user !== null && ! app(HakAkses::class)->allows($user, MenuKey::Customers, Hak::View)) {
                return;
            }
            $contacts = CustomerContact::query()->with('customer')->whereNotNull('birth_date')
                ->whereHas('customer', fn ($q) => BranchLimit::apply($q->where('is_active', true), $user))->get();
            foreach ($contacts as $contact) {
                for ($year = $from->year; $year <= $until->year; $year++) {
                    $day = CarbonImmutable::parse($contact->birth_date)->setYear($year);
                    if ($day->between($from, $until)) {
                        $add($day->toDateString(), 'birthday', __('Birthday: :name · :customer', ['name' => $contact->name, 'customer' => $contact->customer?->name ?? '']));
                    }
                }
            }
        });
        CustomerContact::resolveRelationUsing('customer', fn (CustomerContact $contact) => $contact->belongsTo(Customer::class, 'customer_id', 'id', 'customer'));
        Customer::resolveRelationUsing('customerType', fn (Customer $customer) => $customer->belongsTo(CustomerType::class, 'customer_type_id', 'id', 'customerType'));
        // A customer given a type, or a new one, takes the type's terms; the copy is audited once the customer is saved.
        Customer::saving(function (Customer $customer) use ($context): void {
            if ($customer->customer_type_id && ($customer->isDirty('customer_type_id') || ! $customer->exists)) {
                self::$pending[$customer] = $context->app->make(CustomerTypeTerms::class)->fill($customer);
            }
        });
        Customer::saved(function (Customer $customer) use ($context): void {
            $diff = self::$pending[$customer] ?? [];
            unset(self::$pending[$customer]);
            if ($diff !== []) {
                $context->app->make(CustomerTypeTerms::class)->audit($customer, $diff);
            }
        });
    }

    public static function defaultSeeders(): array
    {
        return [CustomerTypeSeeder::class];
    }

    public static function commands(): array
    {
        return [BirthdaysCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        $schedule->command('central:birthdays')->dailyAt('07:00')->withoutOverlapping()->onOneServer();
    }
}
