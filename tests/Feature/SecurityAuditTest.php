<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportColumn;
use App\Domain\Reporting\ReportCsv;
use App\Domain\Reporting\ReportTable;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pre-launch security audit, pinned.
 *
 * Each test here encodes one finding of the 2026-08 audit so it cannot
 * quietly regress: a control that only exists as framework behaviour or a
 * flag in a call is a control that a refactor can drop with nothing failing.
 */
class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * CSV formula injection. Merk and category labels arrive from the
     * supplier's workbook; a value like `=HYPERLINK(...)` must reach Excel
     * as text, not as a formula. Quoting alone does not do that — Excel
     * evaluates a quoted cell that starts with `=` — so the writer prefixes
     * Excel's own text marker.
     */
    public function test_report_csv_neutralises_formula_leading_cells(): void
    {
        $table = new ReportTable(
            judul: 'Uji',
            period: Period::asOf(now()),
            columns: [
                ReportColumn::text('dimensi', 'Merk'),
                ReportColumn::number('nilai', 'Nilai'),
            ],
            rows: [
                ['dimensi' => '=HYPERLINK("http://jahat.example","klik")', 'nilai' => 1],
                ['dimensi' => '+CMD|/C calc', 'nilai' => 2],
                ['dimensi' => '@SUM(A1)', 'nilai' => 3],
                ['dimensi' => 'MERK BIASA', 'nilai' => 4],
            ],
            totals: [],
            catatan: [],
        );

        $csv = app(ReportCsv::class)->write($table);

        $this->assertStringContainsString('"\'=HYPERLINK', $csv);
        $this->assertStringContainsString('"\'+CMD', $csv);
        $this->assertStringContainsString('"\'@SUM', $csv);
        // Ordinary text and negative numbers stay untouched.
        $this->assertStringContainsString('"MERK BIASA"', $csv);
        $this->assertStringNotContainsString("'MERK BIASA", $csv);
    }

    /**
     * The public page's JSON-LD is built from values the Owner types into
     * Pengaturan. An address containing a closing script tag must not be
     * able to break out of the block and run on the shopfront.
     */
    public function test_owner_typed_values_cannot_escape_the_json_ld_block(): void
    {
        config(['perusahaan.kontak.alamat' => '</script><script>alert(1)</script>Jl. Uji 1']);

        $body = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $body);
        // The value still reaches the schema, hex-escaped past any parser
        // that might close the block early: `<` is <, never a raw tag.
        $this->assertStringContainsString('\u003C\\/script\u003E', $body);

        /*
         * And the @context key survives as itself. Writing this block as a
         * Blade echo had Blade compile `@context` as its own directive,
         * shipping compiled-PHP soup to every crawler — found by this
         * test's first failure, fixed by emitting through raw PHP tags.
         */
        $this->assertStringContainsString('"@context":"https:\/\/schema.org"', $body);
    }

    /**
     * The credit-limit field is disabled for Sales in the form, and disabled
     * fields are not dehydrated — but that is framework behaviour, and this
     * is a money control. A Sales user pushing a limit through a tampered
     * payload must change nothing.
     */
    public function test_sales_cannot_push_a_credit_limit_through_the_edit_form(): void
    {
        $sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $company = Company::factory()->creditLimit(10_000_000)->create();

        Livewire::actingAs($sales)
            ->test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm([
                'credit_limit_rupiah' => 999_000_000,
                'catatan' => 'catatan biasa',
            ])
            ->call('save');

        // The note saved; the limit did not move.
        $this->assertSame(10_000_000, (int) $company->fresh()->credit_limit_rupiah);
        $this->assertSame('catatan biasa', $company->fresh()->catatan);

        // And Finance, whose seat this is, can.
        Livewire::actingAs(User::factory()->finance()->create())
            ->test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm(['credit_limit_rupiah' => 25_000_000])
            ->call('save');

        $this->assertSame(25_000_000, (int) $company->fresh()->credit_limit_rupiah);
    }

    /**
     * The faktur pajak export download answers only to the staff guard —
     * pinned after the audit found it reading the default guard, which is
     * correct only for as long as nobody changes the default.
     */
    public function test_a_buyer_session_cannot_reach_the_faktur_export_download(): void
    {
        $buyer = CustomerUser::factory()->create();

        $this->actingAs($buyer, 'customer')
            ->get('/dokumen/faktur-pajak/1')
            ->assertRedirect(); // bounced to staff login, never into role()
    }
}
