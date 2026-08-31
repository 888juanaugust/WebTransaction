<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\TrialBalance;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Regions\HasRegion;
use App\Domain\Regions\RegionContext;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Region;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Each region is a separate set of books, and queries stay inside one.
 *
 * The requirement is derived from the schema, the same way PortalScopingTest
 * derives the buyer scoping: any table with a `region_id` column must have its
 * model carry HasRegion, so adding a scoped table without the scope fails the
 * build rather than quietly leaking a region's data through the one screen
 * somebody forgot.
 */
class RegionScopingTest extends TestCase
{
    use RefreshDatabase;

    private Region $surabaya;

    private Region $jakarta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->surabaya = $this->currentRegion();
        $this->jakarta = Region::query()->create([
            'kode' => 'JKT',
            'nama' => 'Jakarta',
            'aktif' => true,
        ]);
    }

    public function test_every_table_with_a_region_column_has_a_scoped_model(): void
    {
        $unscoped = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $model = new $class;

            if (! Schema::hasColumn($model->getTable(), 'region_id')) {
                continue;
            }

            /*
             * Two deliberate exceptions. `users` — null means "sees every
             * region", so filtering by the bound region would hide the Owner
             * from their own staff screen. `audit_logs` — the log must record
             * actions that belong to no region (a staff account created, a
             * backup run), and hiding rows from the one screen built to catch
             * everything would defeat it; its own screen filters by column.
             */
            if (in_array($class, [User::class, AuditLog::class], true)) {
                continue;
            }

            if (! in_array(HasRegion::class, class_uses_recursive($class), true)) {
                $unscoped[] = $class;
            }
        }

        $this->assertSame([], $unscoped, 'Models with a region_id column but no HasRegion trait: '
            .implode(', ', $unscoped));
    }

    public function test_a_pinned_query_cannot_see_another_regions_rows(): void
    {
        $context = app(RegionContext::class);

        $milikSurabaya = Company::factory()->create(['nama' => 'Bengkel Surabaya']);
        $milikJakarta = $context->within($this->jakarta, fn () => Company::factory()->create(['nama' => 'Bengkel Jakarta']));

        $this->assertSame(['Bengkel Surabaya'], Company::query()->pluck('nama')->all());

        $context->within($this->jakarta, function () {
            $this->assertSame(['Bengkel Jakarta'], Company::query()->pluck('nama')->all());
        });

        // find() goes through the same scope: the id existing is not enough.
        $this->assertNull(Company::find($milikJakarta->id));
    }

    public function test_new_rows_are_stamped_with_the_bound_region(): void
    {
        $company = Company::factory()->create();

        $this->assertSame($this->surabaya->id, (int) $company->region_id);

        $lain = app(RegionContext::class)->within(
            $this->jakarta,
            fn () => Company::factory()->create(),
        );

        $this->assertSame($this->jakarta->id, (int) $lain->region_id);
    }

    public function test_creating_while_looking_across_all_regions_is_refused(): void
    {
        /*
         * "All regions" is a reading posture. An invoice created in it belongs
         * to nobody's books, and a default would file it in somebody's books
         * silently — the single most expensive wrong line available here.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Pilih satu wilayah/');

        app(RegionContext::class)->acrossAll(fn () => Company::factory()->create());
    }

    public function test_creating_unbound_is_refused_as_a_programming_error(): void
    {
        app(RegionContext::class)->release();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/RegionContext::pinTo/');

        Company::factory()->create();
    }

    public function test_unbound_reads_see_everything_because_jobs_reconcile_the_whole_company(): void
    {
        Company::factory()->create();
        app(RegionContext::class)->within($this->jakarta, fn () => Company::factory()->create());

        app(RegionContext::class)->release();

        $this->assertSame(2, Company::query()->count());
    }

    public function test_within_restores_what_was_bound_before_even_on_failure(): void
    {
        $context = app(RegionContext::class);

        try {
            $context->within($this->jakarta, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // The work failing must not leave the wrong region bound.
        }

        $this->assertSame($this->surabaya->id, $context->regionId());
    }

    public function test_each_region_numbers_its_own_documents(): void
    {
        $numbers = app(DocumentNumberGenerator::class);
        $period = now()->format('Ym');

        $this->assertSame("INV-SBY-{$period}-0001", $numbers->nextInvoiceNumber());
        $this->assertSame("INV-SBY-{$period}-0002", $numbers->nextInvoiceNumber());

        /*
         * Jakarta starts at one, not three: its register is its own. The
         * region code in the number is what lets both INV-…-0001s exist in a
         * column that is unique across the whole database.
         */
        app(RegionContext::class)->within($this->jakarta, function () use ($numbers, $period) {
            $this->assertSame("INV-JKT-{$period}-0001", $numbers->nextInvoiceNumber());
        });

        $this->assertSame("INV-SBY-{$period}-0003", $numbers->nextInvoiceNumber());
    }

    public function test_stock_is_kept_apart_per_region(): void
    {
        $gudangSby = Warehouse::factory()->create(['kode' => 'GD-01']);

        // The same warehouse code in another region is a different warehouse —
        // the unique key is (region_id, kode) now, so this insert succeeding is
        // itself part of the assertion.
        $gudangJkt = app(RegionContext::class)->within(
            $this->jakarta,
            fn () => Warehouse::factory()->create(['kode' => 'GD-01']),
        );

        StockLevel::query()->create([
            'sku' => 'YH-1001', 'warehouse_id' => $gudangSby->id,
            'qty_on_hand' => 40, 'qty_reserved' => 0,
        ]);

        app(RegionContext::class)->within($this->jakarta, function () use ($gudangJkt) {
            StockLevel::query()->create([
                'sku' => 'YH-1001', 'warehouse_id' => $gudangJkt->id,
                'qty_on_hand' => 7, 'qty_reserved' => 0,
            ]);

            $this->assertSame(7, (int) StockLevel::query()->sum('qty_on_hand'));
        });

        $this->assertSame(40, (int) StockLevel::query()->sum('qty_on_hand'));
    }

    public function test_the_books_prove_separately_per_region(): void
    {
        /*
         * The point of the whole feature: each region is a complete set of
         * books. An invoice posted in Surabaya moves Surabaya's Piutang Usaha
         * and leaves Jakarta's untouched.
         */
        $ledger = app(Ledger::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $ledger->post(
            JournalDraft::manual('Penjualan Surabaya')
                ->debit(AccountCode::PIUTANG_USAHA, 1_000_000)
                ->kredit(AccountCode::PENJUALAN, 1_000_000)
        );

        $saldoSurabaya = TrialBalance::asOf(now())->balanceOf(
            AccountCode::PIUTANG_USAHA
        );
        $this->assertSame(1_000_000, $saldoSurabaya);

        app(RegionContext::class)->within($this->jakarta, function () {
            $saldo = TrialBalance::asOf(now())->balanceOf(
                AccountCode::PIUTANG_USAHA
            );

            $this->assertSame(0, $saldo);
        });
    }

    public function test_an_invoice_lookup_by_number_stays_inside_the_region(): void
    {
        /*
         * The concrete leak this design prevents: a Jakarta clerk pasting a
         * Surabaya invoice number into their own screen and reading the row.
         */
        $company = Company::factory()->create();
        $invoice = Invoice::factory()->create(['company_id' => $company->id]);

        app(RegionContext::class)->within($this->jakarta, function () use ($invoice) {
            $this->assertNull(Invoice::query()->where('nomor', $invoice->nomor)->first());
        });
    }
}
