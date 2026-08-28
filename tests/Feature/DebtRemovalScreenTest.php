<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\TeamAssigner;
use App\Domain\Credit\DebtRemovalStatus;
use App\Domain\Credit\DebtRemover;
use App\Domain\Orders\OrderStatus;
use App\Filament\Resources\DebtRemovals\DebtRemovalResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Widgets\DebtRemovalsAwaitingVerification;
use App\Filament\Widgets\OrdersAwaitingApproval;
use App\Models\Company;
use App\Models\DebtRemoval;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The debt-removal claim and the eraser, as the screens offer them.
 *
 * The domain tests pin the rules; these pin that the screens reach the same
 * domain calls and show each seat only what that seat can act on.
 */
class DebtRemovalScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $marketing;

    private User $finance;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $region = $this->currentRegion();
        $this->owner = User::factory()->owner()->create();
        $this->marketing = User::factory()->marketing()->create(['region_id' => $region->id]);
        $this->finance = User::factory()->finance()->create(['region_id' => $region->id]);

        $this->pelanggan = Company::factory()->create();
        app(TeamAssigner::class)->assignMarketing($this->pelanggan, $this->marketing, $this->owner);
    }

    private function openInvoice(): Invoice
    {
        return Invoice::factory()->totalling(3_000_000)->create([
            'company_id' => $this->pelanggan->id,
            'issued_on' => today()->subMonth(),
            'due_date' => today()->subWeek(),
        ]);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_register(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(DebtRemovalResource::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'marketing' => [Role::Marketing, true],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, true],
            'inventori' => [Role::Warehouse, false],
        ];
    }

    public function test_marketing_files_the_claim_from_the_invoice_list(): void
    {
        $invoice = $this->openInvoice();

        Livewire::actingAs($this->marketing)
            ->test(ListInvoices::class)
            ->callTableAction('ajukan_penghapusan', $invoice, [
                'amount_rupiah' => 3_000_000,
                'alasan' => 'Tunai diterima saat kunjungan.',
            ])
            ->assertHasNoTableActionErrors();

        $removal = DebtRemoval::query()->sole();
        $this->assertSame(DebtRemovalStatus::Diajukan, $removal->status);
        $this->assertSame($this->marketing->id, $removal->initiated_by);
    }

    public function test_finance_approves_from_the_dashboard_queue(): void
    {
        $invoice = $this->openInvoice();
        $removal = app(DebtRemover::class)->initiate($invoice, $this->marketing, 3_000_000, 'Tunai.');

        Livewire::actingAs($this->finance)
            ->test(DebtRemovalsAwaitingVerification::class)
            ->callTableAction('setujui_penghapusan', $removal, [
                'catatan' => 'Kas cocok.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(DebtRemovalStatus::Disetujui, $removal->refresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
    }

    public function test_the_verification_queue_belongs_to_finance(): void
    {
        $this->actingAs($this->finance, 'web');
        $this->assertTrue(DebtRemovalsAwaitingVerification::canView());

        $this->actingAs($this->marketing, 'web');
        $this->assertFalse(DebtRemovalsAwaitingVerification::canView());
    }

    public function test_a_marketing_sees_only_their_own_customers_claims(): void
    {
        $milikku = app(DebtRemover::class)->initiate($this->openInvoice(), $this->marketing, 1_000_000, 'Tunai.');

        $lain = User::factory()->marketing()->create(['region_id' => $this->currentRegion()->id]);
        $pelangganLain = Company::factory()->create();
        app(TeamAssigner::class)->assignMarketing($pelangganLain, $lain, $this->owner);
        $invoiceLain = Invoice::factory()->totalling(2_000_000)->create([
            'company_id' => $pelangganLain->id,
            'issued_on' => today()->subMonth(),
            'due_date' => today()->subWeek(),
        ]);
        app(DebtRemover::class)->initiate($invoiceLain, $lain, 2_000_000, 'Tunai.');

        $this->actingAs($this->marketing, 'web');

        $this->assertSame(
            [$milikku->id],
            DebtRemovalResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_marketing_erases_a_submitted_order_from_the_queue(): void
    {
        $order = Order::factory()->status(OrderStatus::Submitted)->create([
            'company_id' => $this->pelanggan->id,
        ]);

        Livewire::actingAs($this->marketing)
            ->test(OrdersAwaitingApproval::class)
            ->callTableAction('hapus', $order, [
                'alasan' => 'Pelanggan batal.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, Order::query()->count());
    }
}
