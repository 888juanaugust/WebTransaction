<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Komisi\KomisiSetter;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Reporting\KomisiReport;
use App\Domain\Reporting\Period;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Komisi follows the money, not the paperwork.
 *
 * The expensive mistakes here are all timing: commission on an invoice that
 * never gets paid, a raise silently rewriting last year's payout, a reversed
 * payment leaving the commission standing. Each one is a test below.
 */
class KomisiReportTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private User $marketing;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 10:00:00');

        $this->owner = User::factory()->owner()->create();
        $this->sales = User::factory()->sales()->create();
        $this->marketing = User::factory()->marketing()->create();

        $this->company = Company::factory()->create([
            'sales_user_id' => $this->sales->id,
            'marketing_user_id' => $this->marketing->id,
        ]);

        // Sales 1,50%, marketing 0,50% — both from long before the fixtures.
        $setter = app(KomisiSetter::class);
        $setter->setRate($this->sales, 150, Carbon::parse('2026-01-01'), $this->owner);
        $setter->setRate($this->marketing, 50, Carbon::parse('2026-01-01'), $this->owner);
    }

    /** An invoice of 11.100.000 with 1.100.000 PPN — base 10.000.000. */
    private function invoice(): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $this->company->id,
            'status' => Invoice::STATUS_OPEN,
            'total_rupiah' => 11_100_000,
            'ppn_rupiah' => 1_100_000,
        ]);
    }

    private function settle(Invoice $invoice, string $date): void
    {
        app(PaymentLedger::class)->recordManualPayment(
            company: $this->company,
            amountRupiah: (int) $invoice->total_rupiah,
            actor: User::factory()->finance()->create(),
            invoice: $invoice,
            paidAt: Carbon::parse($date),
        );
    }

    public function test_commission_lands_in_the_settlement_month_at_each_seats_rate(): void
    {
        $invoice = $this->invoice();

        // Issued whenever; SETTLED in August — August's report owns it.
        $this->settle($invoice, '2026-08-20');

        $agustus = app(KomisiReport::class)->build(Period::month('2026-08'));

        $this->assertCount(2, $agustus->rows);

        $salesRow = collect($agustus->rows)->firstWhere('peran', Role::Sales->label());
        $marketingRow = collect($agustus->rows)->firstWhere('peran', Role::Marketing->label());

        // 1,50% and 0,50% of the 10jt base — never of the PPN.
        $this->assertSame(10_000_000, (int) $salesRow['basis']);
        $this->assertSame(150_000, (int) $salesRow['komisi']);
        $this->assertSame(50_000, (int) $marketingRow['komisi']);

        // July has nothing: an open invoice earns nobody anything.
        $this->assertCount(0, app(KomisiReport::class)->build(Period::month('2026-07'))->rows);
    }

    public function test_a_rate_change_never_rewrites_an_already_settled_month(): void
    {
        $invoice = $this->invoice();
        $this->settle($invoice, '2026-08-20');

        // Doubled from September — August must keep computing at 1,50%.
        app(KomisiSetter::class)->setRate($this->sales, 300, Carbon::parse('2026-09-01'), $this->owner);

        $agustus = app(KomisiReport::class)->build(Period::month('2026-08'));
        $salesRow = collect($agustus->rows)->firstWhere('peran', Role::Sales->label());

        $this->assertSame(150_000, (int) $salesRow['komisi']);
    }

    public function test_a_reversed_payment_claws_the_commission_back(): void
    {
        $invoice = $this->invoice();
        $this->settle($invoice, '2026-08-20');

        $this->assertCount(2, app(KomisiReport::class)->build(Period::month('2026-08'))->rows);

        $entry = $invoice->paymentEntries()->first();
        app(PaymentLedger::class)->reverse($entry, User::factory()->finance()->create(), 'salah entri');

        // The invoice reopened, so the month's commission is simply gone —
        // the claw-back is automatic, not a negative row someone must chase.
        $this->assertCount(0, app(KomisiReport::class)->build(Period::month('2026-08'))->rows);
    }

    public function test_targets_show_achievement_and_surface_the_silent_seat(): void
    {
        $setter = app(KomisiSetter::class);
        $setter->setTarget($this->sales, 2026, 8, 20_000_000, $this->owner);

        // A second sales with a target and no sales at all.
        $diam = User::factory()->sales()->create(['name' => 'Sales Diam']);
        $setter->setTarget($diam, 2026, 8, 15_000_000, $this->owner);

        $this->settle($this->invoice(), '2026-08-20');

        $rows = collect(app(KomisiReport::class)->build(Period::month('2026-08'))->rows);

        $aktif = $rows->firstWhere('user_id', $this->sales->id);
        $this->assertSame(20_000_000, (int) $aktif['target']);
        $this->assertSame(50.0, (float) $aktif['pencapaian']);

        // Zero against a target is a row, not an absence.
        $kosong = $rows->firstWhere('user_id', $diam->id);
        $this->assertNotNull($kosong);
        $this->assertSame(0, (int) $kosong['basis']);
    }

    public function test_only_finance_and_the_owner_set_rates_and_read_the_report(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Keuangan dan Pemilik/');

        try {
            // A marketing seat is paid on it, so it never sets it.
            app(KomisiSetter::class)->setRate(
                $this->sales, 500, Carbon::parse('2026-09-01'),
                User::factory()->marketing()->create(),
            );
        } finally {
            $this->actingAs(User::factory()->finance()->create(), 'web')
                ->get('/admin/laporan/komisi')->assertOk();

            // The seats being paid must not read each other's commission.
            $this->actingAs($this->sales, 'web')
                ->get('/admin/laporan/komisi')->assertForbidden();

            // Finance and the Owner hold the key to the rates; nobody else.
            $this->actingAs($this->owner, 'web')
                ->get('/admin/komisi-target')->assertOk();
            $this->actingAs(User::factory()->finance()->create(), 'web')
                ->get('/admin/komisi-target')->assertOk();
            $this->actingAs(User::factory()->marketing()->create(), 'web')
                ->get('/admin/komisi-target')->assertForbidden();
        }
    }
}
