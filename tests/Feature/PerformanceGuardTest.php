<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Launch\LaunchReadiness;
use App\Domain\Reporting\ReceivablesAgeing;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The volume phase's findings, pinned.
 *
 * Both fixes were found by measuring a seeded year of trading (4,200
 * invoices), not by reading code, and both would regress silently — a
 * dropped index changes no behaviour, a dropped cache changes no answer.
 * These tests are what keeps them.
 */
class PerformanceGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every consumer of "what has come off this invoice" asks the payment
     * ledger by invoice_id; without this index the ageing report scanned
     * the whole ledger once per invoice (measured: 1.1s at one seeded
     * year, and it grows with the square of trading).
     */
    public function test_the_payment_ledger_is_indexed_by_invoice(): void
    {
        $indexes = collect(DB::select(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'payment_entries'"
        ))->pluck('indexdef');

        $this->assertTrue(
            $indexes->contains(fn ($def) => preg_match('/\(invoice_id\)/', $def) === 1),
            'payment_entries needs a lone invoice_id index; the (company_id, invoice_id) composite cannot seek on invoice_id alone.'
        );
    }

    /**
     * The ageing report's query count must not depend on how many invoices
     * exist — its per-invoice sums are subselects inside one query, never
     * a query per row.
     */
    public function test_the_ageing_report_runs_a_constant_number_of_queries(): void
    {
        $company = Company::factory()->create();

        foreach (range(1, 5) as $i) {
            $order = Order::factory()->create(['company_id' => $company->id]);
            Invoice::factory()->create([
                'order_id' => $order->id, 'company_id' => $company->id,
                'total_rupiah' => 1_000_000 * $i,
                'issued_on' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReceivablesAgeing::class)->build();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queries,
            "Ageing ran {$queries} queries for 5 invoices — a per-row query crept in.");
    }

    /**
     * The seeded-password launch check caches its verdict against the
     * stored hash, because bcrypt-verifying every staff account ran on
     * every Owner page load via the nav badge (measured: ~900ms at three
     * accounts). The verdict for a fixed hash never changes, which is what
     * makes the cache sound — this test proves the warm path reads it.
     */
    public function test_the_seeded_password_verdict_is_cached_by_hash(): void
    {
        $user = User::factory()->owner()->create(); // factory password: 'password'
        $kunci = 'sandi-bawaan:'.md5((string) $user->password);

        // Cold: computed, cached, and honest — the account is seeded.
        $this->assertStringContainsString(
            $user->email,
            collect(app(LaunchReadiness::class)->checks())
                ->firstWhere('kunci', 'sandi_staf')->temuan ?? '',
        );
        $this->assertTrue(Cache::get($kunci));

        /*
         * Warm: a fresh instance must read the cache, not bcrypt. Proven by
         * poisoning the cache and watching the answer follow it — wrong on
         * purpose, which a recomputation would silently correct.
         */
        Cache::put($kunci, false);
        $this->app->forgetInstance(LaunchReadiness::class);

        $this->assertTrue(
            collect(app(LaunchReadiness::class)->checks())
                ->firstWhere('kunci', 'sandi_staf')->lulus,
        );
    }
}
