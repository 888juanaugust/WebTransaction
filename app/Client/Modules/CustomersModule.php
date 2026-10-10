<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Domain\Customers\CustomerTypeTerms;
use App\Client\Models\CustomerType;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\CustomerTypeSeeder;
use App\Models\Sales\Customer;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;
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
}
