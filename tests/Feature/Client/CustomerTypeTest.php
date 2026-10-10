<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Domain\Customers\CustomerTypeTerms;
use App\Client\Filament\Resources\CustomerTypes\Pages\ManageCustomerTypes;
use App\Client\Models\CustomerType;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Client\Seeders\CustomerTypeSeeder;
use App\Domain\Sales\Contracts\Prices;
use App\Models\Company\AuditLog;
use App\Models\Company\PaymentTerm;
use App\Models\Sales\PriceCategory;
use Livewire\Livewire;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** A customer type carries a tier (its promo), a payment term and a credit age limit, copied onto its customers. */
class CustomerTypeTest extends TestCase
{
    use OrderFlow;

    private CustomerType $distributor;

    private PriceCategory $tier;

    private PaymentTerm $net45;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
        $this->tier = PriceCategory::query()->create(['name' => 'Distributor tier', 'blanket_discount_percent' => 10]);
        $this->net45 = PaymentTerm::query()->create(['name' => 'Net 45', 'due_days' => 45, 'is_active' => true]);
        $this->distributor = CustomerType::query()->where('code', 'DIST')->firstOrFail();
        $this->distributor->update(['price_category_id' => $this->tier->id, 'payment_term_id' => $this->net45->id, 'credit_limit_age_days' => 60]);
    }

    public function test_the_three_types_are_seeded(): void
    {
        $this->assertSame(array_keys(CustomerTypeSeeder::TYPES), CustomerType::query()->orderBy('id')->pluck('code')->all());
    }

    public function test_setting_a_customers_type_copies_its_terms_and_is_audited(): void
    {
        $this->actingAsAdmin();
        $before = $this->customer->price_category_id;

        $this->customer->update(['customer_type_id' => $this->distributor->id]);
        $customer = $this->customer->fresh();

        $this->assertSame($this->tier->id, $customer->price_category_id);
        $this->assertSame($this->net45->id, $customer->payment_term_id);
        $this->assertTrue($customer->credit_limit_age_enabled);
        $this->assertSame(60, $customer->credit_limit_age_days);
        $log = AuditLog::query()->where('action', 'customer_type_applied')->where('document_id', $customer->id)->sole();
        $this->assertSame($before, $log->meta['changes']['price_category_id']['before']);
        $this->assertSame($this->tier->id, $log->meta['changes']['price_category_id']['after']);
    }

    public function test_a_new_customer_with_a_type_starts_on_its_terms(): void
    {
        $customer = $this->sampleCustomer(['name' => 'New shop', 'number' => 'C-NEW', 'branch_id' => $this->jakarta->id, 'customer_type_id' => $this->distributor->id]);

        $this->assertSame($this->net45->id, $customer->fresh()->payment_term_id);
    }

    public function test_the_types_tier_prices_the_customer_and_its_term_sets_the_due_date(): void
    {
        $this->actingAsAdmin();
        $version = PriceListVersion::query()->create(['effective_from' => '2026-10-01', 'status' => PriceListVersion::PUBLISHED, 'published_at' => now(), 'published_by' => $this->owner->id]);
        PriceListItem::query()->create(['version_id' => $version->id, 'item_id' => $this->item->id, 'price' => 120_000, 'qty_per_ctn' => 12, 'is_active' => true]);
        $this->customer->update(['customer_type_id' => $this->distributor->id]);

        $answer = app(Prices::class)->resolve($this->customer->fresh(), $this->item, $this->item->unit1_id, today(), '1');
        $this->assertSame('tier_discount', $answer['reason']);
        $this->assertSame('10.0000', $answer['discount_percent']);

        $invoice = $this->invoice(1, 100_000);
        $this->assertSame(today()->addDays(45)->toDateString(), $invoice->due_date->toDateString());
    }

    public function test_reapplying_a_type_overwrites_every_customer_of_it(): void
    {
        $this->actingAsAdmin();
        $this->customer->update(['customer_type_id' => $this->distributor->id]);
        $this->customer->update(['payment_term_id' => null, 'credit_limit_age_days' => 5]);
        $this->distributor->update(['credit_limit_age_days' => 90]);

        $changed = app(CustomerTypeTerms::class)->applyToAll($this->distributor->fresh(), $this->owner);

        $this->assertSame(1, $changed);
        $customer = $this->customer->fresh();
        $this->assertSame($this->net45->id, $customer->payment_term_id);
        $this->assertSame(90, $customer->credit_limit_age_days);
        $this->assertSame(0, app(CustomerTypeTerms::class)->applyToAll($this->distributor->fresh(), $this->owner), 'nothing left to change');
    }

    public function test_a_blank_term_on_the_type_leaves_the_customers_own(): void
    {
        $this->distributor->update(['payment_term_id' => null]);
        $own = $this->customer->payment_term_id;

        $this->customer->update(['customer_type_id' => $this->distributor->id]);

        $this->assertSame($own, $this->customer->fresh()->payment_term_id);
    }

    public function test_the_screen_lists_the_types(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ManageCustomerTypes::class)->assertOk()->assertCanSeeTableRecords(CustomerType::all());
    }
}
