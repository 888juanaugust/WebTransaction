<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Portal\Widgets\GrafikBelanja;
use App\Filament\Portal\Widgets\GrafikUmurTagihan;
use App\Filament\Widgets\ArusStok;
use App\Filament\Widgets\NilaiStokKategori;
use App\Filament\Widgets\PendapatanVsBeban;
use App\Filament\Widgets\PenjualanBulanan;
use App\Filament\Widgets\PiutangPerPelanggan;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who sees which chart. The bars carry prices, costs and debts, so each
 * widget's gate is the same role boundary as the screens around it —
 * pinned here so a chart cannot quietly show cost to a seat that must not
 * see it.
 */
class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('gates')]
    public function test_each_chart_shows_only_to_its_seats(string $widget, Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create(), 'web');

        $this->assertSame($allowed, $widget::canView(), "$widget for {$role->value}");
    }

    public static function gates(): array
    {
        $cases = [
            // Sales performance: the selling seats and the Owner.
            [PenjualanBulanan::class, Role::Sales, true],
            [PenjualanBulanan::class, Role::Marketing, true],
            [PenjualanBulanan::class, Role::Owner, true],
            [PenjualanBulanan::class, Role::Warehouse, false],
            [PenjualanBulanan::class, Role::Finance, false],

            // Debt watch: marketing's own, finance and Owner whole-region.
            [PiutangPerPelanggan::class, Role::Marketing, true],
            [PiutangPerPelanggan::class, Role::Finance, true],
            [PiutangPerPelanggan::class, Role::Owner, true],
            [PiutangPerPelanggan::class, Role::Sales, false],
            [PiutangPerPelanggan::class, Role::Warehouse, false],

            // Stock flow: the warehouse and the Owner.
            [ArusStok::class, Role::Warehouse, true],
            [ArusStok::class, Role::Owner, true],
            [ArusStok::class, Role::Sales, false],
            [ArusStok::class, Role::Marketing, false],

            // Stock value is cost data.
            [NilaiStokKategori::class, Role::Warehouse, true],
            [NilaiStokKategori::class, Role::Finance, true],
            [NilaiStokKategori::class, Role::Owner, true],
            [NilaiStokKategori::class, Role::Sales, false],
            [NilaiStokKategori::class, Role::Marketing, false],

            // The laba rugi shape is finance's and the Owner's.
            [PendapatanVsBeban::class, Role::Finance, true],
            [PendapatanVsBeban::class, Role::Owner, true],
            [PendapatanVsBeban::class, Role::Sales, false],
            [PendapatanVsBeban::class, Role::Marketing, false],
            [PendapatanVsBeban::class, Role::Warehouse, false],
        ];

        $out = [];
        foreach ($cases as [$widget, $role, $allowed]) {
            $out[class_basename($widget).' / '.$role->value] = [$widget, $role, $allowed];
        }

        return $out;
    }

    public function test_the_portal_charts_belong_to_a_signed_in_buyer(): void
    {
        $this->assertFalse(GrafikBelanja::canView());
        $this->assertFalse(GrafikUmurTagihan::canView());

        $buyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);
        $this->actingAs($buyer, 'customer');

        $this->assertTrue(GrafikBelanja::canView());
        $this->assertTrue(GrafikUmurTagihan::canView());
    }
}
