<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Filament\Pages\Akuntansi\Jurnal;
use App\Filament\Pages\Akuntansi\LabaRugi;
use App\Filament\Pages\Akuntansi\Neraca;
use App\Filament\Pages\Akuntansi\NeracaSaldo;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The accounting screens: who may open them, and that they render real figures.
 *
 * The access half matters more than the rendering half. These pages show
 * margin, cost and the whole balance sheet — everything Sales and Warehouse are
 * deliberately kept away from elsewhere — so a page that forgot its guard would
 * undo the role separation the rest of the system is careful about.
 */
class AccountingScreensTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: class-string}> */
    public static function pages(): array
    {
        return [
            'neraca' => [Neraca::class],
            'laba rugi' => [LabaRugi::class],
            'neraca saldo' => [NeracaSaldo::class],
            'jurnal' => [Jurnal::class],
        ];
    }

    /** @return array<string, array{0: Role, 1: bool}> */
    public static function roles(): array
    {
        return [
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
        ];
    }

    #[DataProvider('pages')]
    public function test_finance_can_open_every_accounting_screen(string $page): void
    {
        $this->seedSomeTrading();

        Livewire::actingAs($this->staff(Role::Finance))
            ->test($page)
            ->assertOk();
    }

    #[DataProvider('roles')]
    public function test_only_finance_and_the_owner_may_see_the_books(Role $role, bool $allowed): void
    {
        $this->actingAs($this->staff($role));

        foreach (array_column(static::pages(), 0) as $page) {
            $this->assertSame($allowed, $page::canAccess(), "{$role->value} vs {$page}");
        }
    }

    #[DataProvider('roles')]
    public function test_the_route_itself_refuses_not_just_the_menu(Role $role, bool $allowed): void
    {
        // Hiding a nav item is not access control. The URL has to refuse too.
        $response = $this->actingAs($this->staff($role), 'web')->get(Neraca::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_the_neraca_shows_real_figures_and_says_it_balances(): void
    {
        $this->seedSomeTrading();

        Livewire::actingAs($this->staff(Role::Owner))
            ->test(Neraca::class)
            ->assertOk()
            ->assertSee('Rp 50.000.000')          // Modal disetor
            ->assertSee('Laba tahun berjalan')
            ->assertDontSee('tidak seimbang');
    }

    public function test_the_neraca_honours_the_date_it_is_given(): void
    {
        $this->seedSomeTrading();

        Livewire::actingAs($this->staff(Role::Owner))
            ->test(Neraca::class)
            ->set('tanggal', '2020-01-01')
            ->assertDontSee('Rp 50.000.000');
    }

    public function test_a_nonsense_date_falls_back_instead_of_erroring(): void
    {
        // A date input can be cleared, and a blank string is not a date.
        $this->seedSomeTrading();

        Livewire::actingAs($this->staff(Role::Owner))
            ->test(Neraca::class)
            ->set('tanggal', '')
            ->assertOk()
            ->set('tanggal', 'bukan tanggal')
            ->assertOk();
    }

    public function test_a_backwards_period_is_clamped_rather_than_reported_as_a_quiet_month(): void
    {
        $this->seedSomeTrading();

        $page = Livewire::actingAs($this->staff(Role::Owner))
            ->test(LabaRugi::class)
            ->set('dari', '2026-08-01')
            ->set('sampai', '2026-01-01');

        $this->assertSame('2026-08-01', $page->instance()->to()->toDateString());
    }

    public function test_the_laba_rugi_separates_cost_of_sales_from_overhead(): void
    {
        $this->seedSomeTrading();

        Livewire::actingAs($this->staff(Role::Finance))
            ->test(LabaRugi::class)
            ->set('dari', '2026-01-01')
            ->set('sampai', '2026-12-31')
            ->assertOk()
            ->assertSee('Laba kotor')
            ->assertSee('Laba bersih')
            ->assertSee('Harga Pokok Penjualan')
            ->assertSee('Beban Operasional');
    }

    public function test_the_trial_balance_reports_the_control_accounts_agreeing(): void
    {
        $this->seedRealTrading();

        Livewire::actingAs($this->staff(Role::Finance))
            ->test(NeracaSaldo::class)
            ->assertOk()
            ->assertSee('Akun kontrol terhadap buku pembantu')
            ->assertSee('Piutang Usaha')
            ->assertDontSee('tidak lagi cocok');
    }

    public function test_the_trial_balance_says_so_when_a_control_account_drifts(): void
    {
        $this->seedRealTrading();

        // A manual journal into a control account: the ordinary way this
        // breaks, and the screen has to name it rather than look fine.
        app(Ledger::class)->postManual(
            JournalDraft::manual('Koreksi tanpa dokumen')
                ->debit(AccountCode::PIUTANG_USAHA, 1_000_000)
                ->kredit(AccountCode::PENJUALAN, 1_000_000),
            $this->staff(Role::Finance),
        );

        Livewire::actingAs($this->staff(Role::Finance))
            ->test(NeracaSaldo::class)
            ->assertOk()
            ->assertSee('tidak lagi cocok');

        $this->assertSame('1', NeracaSaldo::getNavigationBadge());
    }

    public function test_there_is_no_badge_when_nothing_has_drifted(): void
    {
        $this->actingAs($this->staff(Role::Finance));
        $this->seedRealTrading();

        $this->assertNull(NeracaSaldo::getNavigationBadge());
    }

    public function test_an_account_holding_the_wrong_sign_is_flagged_on_the_screen(): void
    {
        // seedSomeTrading() credits Persediaan without ever receiving any, so
        // inventory goes negative — which is exactly the case the badge is for.
        $this->seedSomeTrading();

        Livewire::actingAs($this->staff(Role::Finance))
            ->test(NeracaSaldo::class)
            ->assertOk()
            ->assertSee('saldo terbalik');
    }

    public function test_the_journal_lists_entries_with_the_accounts_they_touched(): void
    {
        $this->seedSomeTrading();

        Livewire::actingAs($this->staff(Role::Finance))
            ->test(Jurnal::class)
            ->assertOk()
            ->assertSee('Setoran modal')
            ->assertSee('JU-');
    }

    public function test_every_document_backed_entry_offers_a_link_that_resolves(): void
    {
        /*
         * The link has to open, not merely exist. Every one of these documents
         * is posted by the time it has a journal entry, and every resource
         * refuses to edit a posted document — so an edit link is a button that
         * 403s. Following each URL for real is the only assertion that catches
         * that; asserting the URL is non-null does not.
         */
        $this->seedRealTrading();

        $linkable = [
            JournalEntry::JENIS_PENJUALAN,
            JournalEntry::JENIS_HPP,
            JournalEntry::JENIS_PENERIMAAN_BARANG,
        ];

        foreach ($linkable as $jenis) {
            $entry = JournalEntry::query()->where('jenis', $jenis)->firstOrFail();
            $url = Jurnal::sourceUrl($entry);

            $this->assertNotNull($url, "{$jenis} has no link to its document.");
            $this->actingAs($this->staff(Role::Finance), 'web')
                ->get($url)
                ->assertOk("{$jenis} links to a page its own reader cannot open.");
        }
    }

    public function test_the_journal_shows_whole_rupiah_not_centavos(): void
    {
        // Rupiah has no subunit in practice, and ->money('IDR') renders ",00".
        $this->seedSomeTrading();

        Livewire::actingAs($this->staff(Role::Finance))
            ->test(Jurnal::class)
            ->assertOk()
            ->assertSee('Rp 50.000.000')
            ->assertDontSee('50.000.000,00');
    }

    public function test_an_entry_with_no_document_offers_no_broken_link(): void
    {
        // Manual journals and payment entries have no screen of their own.
        // A link that goes nowhere is worse than no link.
        $this->seedSomeTrading();

        $manual = JournalEntry::query()->where('jenis', JournalEntry::JENIS_MANUAL)->firstOrFail();

        $this->assertNull(Jurnal::sourceUrl($manual));
    }

    // --- helpers ------------------------------------------------------------

    private function staff(Role $role): User
    {
        return User::factory()->role($role)->create();
    }

    /**
     * Books built from real documents, so the control accounts genuinely tie.
     *
     * The manual-journal fixture below cannot be used for the reconciliation
     * tests: journals into Piutang and Persediaan with no invoice or receipt
     * behind them are precisely the drift the panel exists to report, so a
     * screen showing "agrees" against that data would be showing a lie.
     */
    private function seedRealTrading(): void
    {
        $finance = $this->staff(Role::Finance);
        $sales = User::factory()->sales()->create();
        $warehouse = Warehouse::factory()->create();
        $supplier = Supplier::factory()->create(['payment_terms_days' => 30]);
        $company = Company::factory()->creditLimit(500_000_000)->create(['payment_terms_days' => 30]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);
        Product::factory()->create(['kode' => 'YH-SCR-1', 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => 'YH-SCR-1', 'harga' => 100_000,
        ]);

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces(100, 60_000)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => 'YH-SCR-1', 'urutan' => 1,
        ]);
        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $finance);

        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextOrderNumber(),
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $sales->id,
        ]);
        OrderLine::factory()->qty(50)->create([
            'order_id' => $order->id, 'sku' => 'YH-SCR-1', 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $sales);
        $machine->confirm($order->refresh(), $sales);
        $machine->awaitPayment($order->refresh(), $sales);

        // Ship it too, so there is a cost entry as well as a revenue one.
        // Without this the journal has no HPP row to link-check.
        $machine->markPaid($order->refresh(), ['sumber' => 'test']);
        $machine->ship($order->refresh(), $this->staff(Role::Warehouse));
    }

    /** Capital in, a sale, its cost, and an overhead — enough for every screen. */
    private function seedSomeTrading(): void
    {
        $finance = $this->staff(Role::Finance);
        $ledger = app(Ledger::class);

        foreach ([
            ['Setoran modal', AccountCode::BANK, AccountCode::MODAL_DISETOR, 50_000_000],
            ['Penjualan', AccountCode::PIUTANG_USAHA, AccountCode::PENJUALAN, 10_000_000],
            ['HPP', AccountCode::HARGA_POKOK_PENJUALAN, AccountCode::PERSEDIAAN, 6_000_000],
            ['Beban sewa', AccountCode::BEBAN_OPERASIONAL, AccountCode::BANK, 1_500_000],
        ] as [$keterangan, $debit, $kredit, $amount]) {
            $ledger->postManual(
                JournalDraft::manual($keterangan, new \DateTime('2026-08-10'))
                    ->debit($debit, $amount)
                    ->kredit($kredit, $amount),
                $finance,
            );
        }
    }
}
