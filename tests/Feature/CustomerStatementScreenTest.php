<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Pages\Laporan\RekeningPelanggan;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerStatementScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->creditLimit(100_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'status' => Company::STATUS_ACTIVE,
        ]);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(RekeningPelanggan::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_who_may_print_it(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(route('dokumen.rekening-pelanggan', $this->company));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            // Sales reach it for the same reason they reach the ageing report:
            // they ring about overdue invoices, and there is no cost on it.
            'sales' => [Role::Sales, true],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_it_opens_on_no_customer_rather_than_a_guess(): void
    {
        /*
         * A statement that opens on whichever company sorts first is one
         * somebody prints and sends to the wrong person.
         */
        Livewire::actingAs(User::factory()->role(Role::Finance)->create())
            ->test(RekeningPelanggan::class)
            ->assertSet('companyId', null)
            ->assertSee('Pilih pelanggan dulu');
    }

    public function test_choosing_a_customer_builds_their_statement(): void
    {
        Invoice::factory()->totalling(9_000_000)->create([
            'company_id' => $this->company->id,
            'issued_on' => now()->subDays(10)->toDateString(),
        ]);

        Livewire::actingAs(User::factory()->role(Role::Finance)->create())
            ->test(RekeningPelanggan::class)
            ->set('companyId', $this->company->id)
            ->assertSee('CV Sinar Distribusi')
            ->assertSee('Rp 9.000.000');
    }

    public function test_the_printed_statement_carries_the_customer_and_the_balance(): void
    {
        Invoice::factory()->totalling(9_000_000)->create([
            'company_id' => $this->company->id,
            'issued_on' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs(User::factory()->role(Role::Finance)->create(), 'web')
            ->get(route('dokumen.rekening-pelanggan', [
                'company' => $this->company,
                'dari' => now()->subMonth()->toDateString(),
                'sampai' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('CV Sinar Distribusi')
            ->assertSee('Rp 9.000.000')
            ->assertSee('Saldo terutang');
    }

    public function test_a_mistyped_date_falls_back_rather_than_erroring(): void
    {
        // A statement is something a customer reads. A 500 on a bad query
        // string is worse than a sensible window.
        $this->actingAs(User::factory()->role(Role::Finance)->create(), 'web')
            ->get(route('dokumen.rekening-pelanggan', [
                'company' => $this->company,
                'dari' => 'kemarin-siang',
                'sampai' => '',
            ]))
            ->assertOk();
    }

    public function test_the_printed_statement_shows_no_cost_or_credit_limit(): void
    {
        /*
         * It goes to the customer. What we paid for the goods is never their
         * business, and the credit limit is ours to set rather than theirs to
         * negotiate against.
         */
        Invoice::factory()->totalling(9_000_000)->create([
            'company_id' => $this->company->id,
            'issued_on' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs(User::factory()->role(Role::Finance)->create(), 'web')
            ->get(route('dokumen.rekening-pelanggan', $this->company))
            ->assertOk()
            ->assertDontSee('HPP')
            ->assertDontSee('Harga pokok')
            ->assertDontSee('Batas kredit')
            ->assertDontSee('100.000.000');
    }
}
