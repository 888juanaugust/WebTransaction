<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reporting\Period;
use App\Domain\Reporting\RekapPpn;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keluaran − Masukan, netted the way it is filed.
 *
 * The report only reads documents that already exist; what it must get
 * right is which month each side belongs to — keluaran by issue date,
 * masukan by the supplier's own faktur date — and the sign of the answer.
 */
class RekapPpnTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_month_nets_output_against_input_ppn(): void
    {
        // Keluaran: 1.100.000 issued in August, and one in September that
        // must stay out of August's masa.
        Invoice::factory()->create([
            'status' => Invoice::STATUS_OPEN,
            'ppn_rupiah' => 1_100_000, 'issued_on' => '2026-08-10',
        ]);
        Invoice::factory()->create([
            'status' => Invoice::STATUS_PAID,
            'ppn_rupiah' => 999_999, 'issued_on' => '2026-09-02',
        ]);

        // Masukan: a supplier bill whose faktur is dated in August.
        SupplierBill::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'status' => SupplierBill::STATUS_OPEN,
            'ppn_rupiah' => 400_000, 'tanggal_faktur' => '2026-08-15',
        ]);

        $rekap = app(RekapPpn::class)->build(Period::month('2026-08'));

        $this->assertSame(1_100_000 - 400_000, (int) $rekap->totals['jumlah']);
        $this->assertStringContainsString('KURANG BAYAR', (string) $rekap->totals['pos']);
    }

    public function test_the_screen_is_for_the_person_who_files(): void
    {
        $this->actingAs(User::factory()->finance()->create(), 'web')
            ->get('/admin/laporan/rekap-ppn')->assertOk();

        // Sales issue the invoices behind it and still cannot file.
        $this->actingAs(User::factory()->sales()->create(), 'web')
            ->get('/admin/laporan/rekap-ppn')->assertForbidden();
    }
}
