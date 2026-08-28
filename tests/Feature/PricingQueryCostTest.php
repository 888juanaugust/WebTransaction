<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\Company;
use App\Models\CompanyPriceOverride;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\PriceTier;
use App\Models\PriceTierItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pricing cost, asserted rather than assumed.
 *
 * resolve() ran four queries per SKU — effective version, list item, company
 * overrides, tier items. Confirming a 20-line order therefore ran ~80 queries
 * *inside the transaction holding the stock row locks*, so the cost was not
 * only latency: it was time every other confirmation spent blocked.
 *
 * The fix is a prime() that loads all four sets for a batch. That is easy to
 * undo by accident — a helpful-looking `resolve()` added back inside a loop
 * would restore the old behaviour with the whole suite still green — so the
 * query count is asserted here.
 *
 * Correctness matters more than the count, so every case below also checks
 * that the primed answer equals the cold one.
 */
class PricingQueryCostTest extends TestCase
{
    use RefreshDatabase;

    private const SKUS = 20;

    private Company $company;

    private PriceListVersion $version;

    /** @var list<string> */
    private array $skus = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tier = PriceTier::factory()->create(['discount_bps' => 500]);
        $this->company = Company::factory()->creditLimit(9_000_000_000)->create([
            'price_tier_id' => $tier->id,
        ]);

        $this->version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        for ($i = 0; $i < self::SKUS; $i++) {
            $kode = 'QC-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);

            Product::factory()->create(['kode' => $kode, 'qty_per_ctn' => 12]);
            PriceListItem::factory()->create([
                'version_id' => $this->version->id,
                'kode' => $kode,
                'harga' => 100_000 + ($i * 1_000),
            ]);

            $this->skus[] = $kode;
        }

        // A spread of rules so the batch has real work to split up, not just a
        // list price for every SKU.
        CompanyPriceOverride::factory()->create([
            'company_id' => $this->company->id, 'kode' => $this->skus[0], 'harga' => 55_000,
        ]);
        CompanyPriceOverride::factory()->create([
            'company_id' => $this->company->id, 'kode' => null, 'discount_bps' => 200,
            'min_qty_base' => 100,
        ]);
        PriceTierItem::factory()->create([
            'price_tier_id' => $tier->id, 'kode' => $this->skus[1], 'discount_bps' => 1_500,
        ]);
    }

    /** A fresh resolver with nothing cached — what the old code did every call. */
    private function cold(): PriceResolver
    {
        return new PriceResolver;
    }

    /** @return array{0: int, 1: array<string, int|null>} queries run, prices resolved */
    private function priceEverything(PriceResolver $resolver, bool $prime, int $qty = 10): array
    {
        if ($prime) {
            $resolver->prime($this->company, $this->skus);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $prices = [];

        foreach ($this->skus as $sku) {
            $prices[$sku] = $resolver->resolve($this->company, $sku, $qty)->unitPrice;
        }

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $prices];
    }

    // --- the count ----------------------------------------------------------

    public function test_priming_makes_pricing_a_batch_cost_nothing_per_sku(): void
    {
        $resolver = $this->cold();
        $resolver->prime($this->company, $this->skus);

        [$queries] = $this->priceEverything($resolver, prime: false);

        $this->assertSame(
            0,
            $queries,
            'After priming, resolving the primed SKUs must not touch the database again.'
        );
    }

    public function test_priming_a_batch_costs_a_fixed_number_of_queries(): void
    {
        $resolver = $this->cold();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver->prime($this->company, $this->skus);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Effective version, list items, company overrides, tier items.
        $this->assertLessThanOrEqual(
            5,
            $queries,
            "Priming {$queries} queries for ".self::SKUS.' SKUs — the cost is supposed to be fixed.'
        );
    }

    /**
     * The guard that actually matters: cost must not scale with the batch.
     * Doubling the SKUs must not double the queries.
     */
    public function test_the_cost_of_priming_does_not_grow_with_the_number_of_skus(): void
    {
        $half = array_slice($this->skus, 0, (int) (self::SKUS / 2));

        $countFor = function (array $skus): int {
            $resolver = $this->cold();

            // A fresh Company each time: priming loads the price tier onto the
            // instance, so reusing one would make the second measurement look
            // cheaper for a reason that has nothing to do with batch size.
            $company = Company::findOrFail($this->company->id);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $resolver->prime($company, $skus);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame(
            $countFor($half),
            $countFor($this->skus),
            'Twice the SKUs must cost the same number of queries, or this is still an N+1.'
        );
    }

    /**
     * Even unprimed, the effective version is read once rather than once per
     * SKU — every caller benefits, including ones that cannot batch.
     */
    public function test_the_effective_version_is_read_once_per_resolver(): void
    {
        $resolver = $this->cold();

        [$queries] = $this->priceEverything($resolver, prime: false);

        $this->assertLessThan(
            self::SKUS * 4,
            $queries,
            'The effective price list version is the same row for every SKU on the same date.'
        );
    }

    public function test_confirming_an_order_does_not_price_line_by_line(): void
    {
        $warehouse = Warehouse::factory()->create();
        $sales = User::factory()->sales()->create();

        $order = Order::factory()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $sales->id,
        ]);

        foreach ($this->skus as $sku) {
            OrderLine::factory()->qty(12)->create(['order_id' => $order->id, 'sku' => $sku]);

            app(StockLedger::class)->record(
                $sku, $warehouse->id, 120, MovementReason::Penerimaan
            );
        }

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $sales);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $machine->confirm($order->refresh(), $this->approver());
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        /*
         * Confirming does far more than pricing: it locks a stock row per line,
         * writes a reservation, saves a snapshot, checks credit. Counting the
         * lot would measure that work rather than this change — and would go
         * red the next time reservation gains a query.
         *
         * So count only the reads against the pricing tables.
         */
        $pricing = array_values(array_filter(
            $log,
            fn (array $q) => (bool) preg_match(
                '/\b(price_list_versions|price_list_items|company_price_overrides|price_tier_items|price_tiers)\b/',
                $q['query'],
            ),
        ));

        $this->assertLessThanOrEqual(
            5,
            count($pricing),
            'Confirming a '.self::SKUS.'-line order ran '.count($pricing).' pricing queries inside the '
            .'stock-lock transaction — it is pricing line by line again.'
        );
    }

    // --- and it is still the same price -------------------------------------

    public function test_a_primed_resolution_equals_a_cold_one(): void
    {
        foreach ([1, 10, 100, 5_000] as $qty) {
            $primed = $this->cold();
            $primed->prime($this->company, $this->skus);

            [, $fromCache] = $this->priceEverything($primed, prime: false, qty: $qty);

            $fromDatabase = [];

            foreach ($this->skus as $sku) {
                // A brand-new resolver each time: no cache at all.
                $fromDatabase[$sku] = $this->cold()->resolve($this->company, $sku, $qty)->unitPrice;
            }

            $this->assertSame(
                $fromDatabase,
                $fromCache,
                "Priming changed a price at qty {$qty}."
            );
        }
    }

    public function test_a_primed_resolution_carries_the_same_reason(): void
    {
        $primed = $this->cold();
        $primed->prime($this->company, $this->skus);

        foreach ($this->skus as $sku) {
            $cached = $primed->resolve($this->company, $sku, 10);
            $fresh = $this->cold()->resolve($this->company, $sku, 10);

            $this->assertSame($fresh->reason, $cached->reason, "reason differs for {$sku}");
            $this->assertSame($fresh->meta, $cached->meta, "meta differs for {$sku}");
        }
    }

    /**
     * Priming a SKU that has no price must remember the *absence*, or every
     * lookup for an unpriced SKU goes back to the database for a row that is
     * not there — the N+1 surviving in the one case nobody checks.
     */
    public function test_an_unpriced_sku_is_cached_as_unpriced(): void
    {
        $resolver = $this->cold();
        $resolver->prime($this->company, ['DOES-NOT-EXIST']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolution = $resolver->resolve($this->company, 'DOES-NOT-EXIST', 1);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertFalse($resolution->isPriced());
        $this->assertSame(0, $queries, 'A known-absent SKU must not be looked up again.');
    }

    /** Priming one batch must not answer for a SKU outside it. */
    public function test_priming_one_batch_does_not_answer_for_another_sku(): void
    {
        $outsider = 'QC-OUTSIDE';
        Product::factory()->create(['kode' => $outsider]);
        PriceListItem::factory()->create([
            'version_id' => $this->version->id, 'kode' => $outsider, 'harga' => 777_000,
        ]);

        $resolver = $this->cold();
        $resolver->prime($this->company, $this->skus);

        $this->assertSame(
            $this->cold()->resolve($this->company, $outsider, 1)->unitPrice,
            $resolver->resolve($this->company, $outsider, 1)->unitPrice,
        );
    }

    /**
     * Publishing a new version while a resolver is warm must not keep serving
     * the old prices. The importer calls forget(); this is that contract.
     */
    public function test_a_warm_resolver_is_dropped_when_a_new_version_is_published(): void
    {
        $resolver = $this->cold();
        $before = $resolver->resolve($this->company, $this->skus[2], 10)->unitPrice;

        $newer = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $newer->id, 'kode' => $this->skus[2], 'harga' => 999_000,
        ]);

        $this->assertSame(
            $before,
            $resolver->resolve($this->company, $this->skus[2], 10)->unitPrice,
            'A warm resolver is expected to hold its version for the life of the request.'
        );

        $resolver->forget();

        $this->assertNotSame(
            $before,
            $resolver->resolve($this->company, $this->skus[2], 10)->unitPrice,
            'forget() must send the resolver back to the database.'
        );
    }
}
