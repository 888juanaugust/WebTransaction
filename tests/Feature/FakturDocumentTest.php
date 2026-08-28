<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Terbilang;
use App\Filament\Resources\Orders\Pages\ViewOrder as AdminViewOrder;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\User;
use App\Models\VirtualAccount;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The faktur — the invoice the customer is actually handed.
 *
 * The surat jalan's opposite number in both directions. That document carries
 * no money and only the warehouse may print it; this one is nothing but money
 * and the warehouse may not see it at all. The two together are the reason the
 * role split exists, so both halves of the inversion are asserted here.
 *
 * The other half of this file is tenancy. A buyer can reach their own invoices
 * through a URL that takes an id, which is exactly the shape of bug that leaks
 * one customer's trading terms to another.
 */
class FakturDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-FK-1';

    private const UNIT_PRICE = 500_000;

    private Warehouse $warehouse;

    private User $sales;

    private Company $company;

    private Invoice $invoice;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // No secret key, so VAs are minted locally rather than over the wire.
        config()->set('xendit.secret_key', '');

        $this->warehouse = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->sales = User::factory()->sales()->create();

        $this->company = Company::factory()->creditLimit(900_000_000)->create([
            'nama' => 'Bengkel Uji Faktur',
            'payment_terms_days' => 30,
            'npwp' => '01.234.567.8-901.000',
            'nama_wajib_pajak' => 'CV Uji Faktur',
            'alamat_pajak' => 'Jl. Pajak No. 7, Bekasi',
        ]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 12, 'satuan_dasar' => 'PCS',
            'merk' => 'YUHOLI', 'description' => 'Master rem depan',
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => self::UNIT_PRICE,
        ]);

        app(StockLedger::class)->record(self::SKU, $this->warehouse->id, 500, MovementReason::Penerimaan);

        $this->order = Order::factory()->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextOrderNumber(),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
            'po_pelanggan' => 'PO-7788',
        ]);

        OrderLine::factory()->qty(10)->create(['order_id' => $this->order->id, 'sku' => self::SKU]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($this->order->refresh(), $this->sales);
        $machine->confirm($this->order->refresh(), $this->approver());
        // awaitPayment is what issues the invoice and provisions the VA.
        $machine->awaitPayment($this->order->refresh(), $this->sales);

        $this->order->refresh();
        $this->invoice = $this->order->invoice()->firstOrFail();
    }

    private function staffUrl(?Invoice $invoice = null): string
    {
        return route('dokumen.faktur', $invoice ?? $this->invoice);
    }

    private function buyerUrl(?Invoice $invoice = null): string
    {
        return route('portal.dokumen.faktur', $invoice ?? $this->invoice);
    }

    private function buyer(?Company $company = null): CustomerUser
    {
        return CustomerUser::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
        ]);
    }

    // --- the document -------------------------------------------------------

    public function test_the_faktur_carries_the_invoice_and_the_customer(): void
    {
        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee('Faktur')
            ->assertSee($this->invoice->nomor)
            ->assertSee($this->order->nomor)
            ->assertSee('PO-7788')
            ->assertSee(self::SKU)
            ->assertSee('Master rem depan');
    }

    /**
     * The tax identity is snapshotted onto the invoice at issue, and the
     * document must print the snapshot rather than the customer record. Renaming
     * a company must not silently rewrite the fakturs already in their files.
     */
    public function test_the_faktur_prints_the_snapshotted_tax_identity(): void
    {
        $this->company->update([
            'nama_wajib_pajak' => 'CV Nama Baru Sekali',
            'npwp' => '99.999.999.9-999.999',
        ]);

        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee('CV Uji Faktur')
            ->assertSee('01.234.567.8-901.000')
            ->assertSee('Jl. Pajak No. 7, Bekasi')
            ->assertDontSee('CV Nama Baru Sekali')
            ->assertDontSee('99.999.999.9-999.999');
    }

    /**
     * The rule that gives this document its shape: DPP and PPN per line, never
     * only on the total. Summing rounded lines is not the same number as
     * rounding a summed total, and the difference lands on a faktur pajak.
     */
    public function test_dpp_and_ppn_appear_on_every_line_and_foot_to_the_totals(): void
    {
        $line = $this->order->lines()->firstOrFail();

        $this->assertGreaterThan(0, $line->dpp_rupiah, 'the fixture must have priced lines');

        $html = $this->actingAs($this->sales)->get($this->staffUrl())->assertOk()->getContent();

        // Every money column on the line, in the document's own formatting.
        foreach (['line_total_rupiah', 'dpp_rupiah', 'ppn_rupiah'] as $column) {
            $this->assertStringContainsString(
                number_format((int) $line->$column, 0, ',', '.'),
                $html,
                "line {$column} must be printed"
            );
        }

        // With a single line, each column also foots to its totals row — which
        // is the property the layout exists to preserve.
        $this->assertSame((int) $line->line_total_rupiah, $this->invoice->subtotal_rupiah);
        $this->assertSame((int) $line->dpp_rupiah, $this->invoice->dpp_rupiah);
        $this->assertSame((int) $line->ppn_rupiah, $this->invoice->ppn_rupiah);
    }

    public function test_the_faktur_states_the_tax_basis_and_the_coretax_code(): void
    {
        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee('11/12')
            ->assertSee('PMK 131/2024')
            // Code 04, not 01: ordinary non-luxury goods on the DPP nilai lain.
            ->assertSee('Kode transaksi 04');
    }

    /**
     * A commercial invoice is not a Faktur Pajak, and a customer who assumes it
     * is will not find out until they try to claim the input tax. The document
     * has to say which one it is on its face.
     */
    public function test_without_an_nsfp_the_faktur_says_the_tax_document_is_still_to_come(): void
    {
        $this->assertNull($this->invoice->nsfp, 'the fixture must not have an NSFP yet');

        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee('Faktur komersial')
            ->assertSee('Faktur Pajak akan diterbitkan terpisah melalui Coretax');
    }

    public function test_once_coretax_returns_an_nsfp_the_faktur_prints_it(): void
    {
        $this->invoice->update(['nsfp' => '0400012512345678']);

        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee('0400012512345678')
            ->assertDontSee('akan diterbitkan terpisah');
    }

    /** The total in words, which is the line a bookkeeper checks the digits against. */
    public function test_the_faktur_carries_the_amount_in_words(): void
    {
        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee('Terbilang')
            ->assertSee(Terbilang::rupiah($this->invoice->total_rupiah));
    }

    // --- where to pay -------------------------------------------------------

    public function test_an_unpaid_faktur_shows_the_virtual_account_and_what_is_owed(): void
    {
        $va = VirtualAccount::query()
            ->where('company_id', $this->company->id)
            ->where('status', 'active')
            ->firstOrFail();

        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee($va->account_number)
            ->assertSee('Jumlah yang harus dibayar')
            ->assertSee(number_format($this->invoice->total_rupiah, 0, ',', '.'));
    }

    /**
     * Outstanding comes from summing the append-only payment ledger, so a
     * part-payment has to move the figure on the document without anything
     * having edited the invoice.
     */
    public function test_a_part_paid_faktur_asks_only_for_the_remainder(): void
    {
        $finance = User::factory()->role(Role::Finance)->create();

        app(PaymentLedger::class)->recordManualPayment(
            company: $this->company,
            amountRupiah: 1_000_000,
            actor: $finance,
            invoice: $this->invoice,
        );

        $remaining = $this->invoice->refresh()->amountOutstanding();

        $this->assertSame($this->invoice->total_rupiah - 1_000_000, $remaining);

        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee(number_format($remaining, 0, ',', '.'))
            ->assertSee('sudah dibayar');
    }

    public function test_a_fully_paid_faktur_says_lunas_and_stops_asking_for_money(): void
    {
        app(PaymentLedger::class)->recordManualPayment(
            company: $this->company,
            amountRupiah: $this->invoice->total_rupiah,
            actor: User::factory()->role(Role::Finance)->create(),
            invoice: $this->invoice,
        );

        $this->actingAs($this->sales)->get($this->staffUrl())
            ->assertOk()
            ->assertSee('LUNAS')
            ->assertDontSee('Jumlah yang harus dibayar');
    }

    // --- who may print it ---------------------------------------------------

    /** @return list<array{0: Role, 1: bool}> */
    public static function staffRoles(): array
    {
        return [
            'sales' => [Role::Sales, true],
            'finance' => [Role::Finance, true],
            'owner' => [Role::Owner, true],
            // The exact inverse of the surat jalan, and the whole point of the
            // split: the warehouse ships the goods and never sees the money.
            'warehouse' => [Role::Warehouse, false],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_only_roles_that_may_see_money_can_print_a_faktur(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create())
            ->get($this->staffUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_staff_login(): void
    {
        $this->get($this->staffUrl())
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    /**
     * The portal document lives outside the portal panel, so the panel's own
     * redirect does not cover it. Sending a buyer to the staff login hands them
     * a form their credentials cannot satisfy.
     */
    public function test_a_guest_on_the_portal_document_is_sent_to_the_portal_login(): void
    {
        $this->get($this->buyerUrl())
            ->assertRedirect(route('filament.portal.auth.login'));
    }

    // --- tenancy ------------------------------------------------------------

    public function test_a_buyer_can_print_their_own_faktur(): void
    {
        $this->actingAs($this->buyer(), 'customer')
            ->get($this->buyerUrl())
            ->assertOk()
            ->assertSee($this->invoice->nomor)
            ->assertSee(self::SKU);
    }

    /**
     * The failure this whole test file exists for: an id in a URL is an
     * invitation to try the next one, and what leaks is a competitor's prices.
     *
     * 404 rather than 403 on purpose — a 403 confirms the invoice exists.
     */
    public function test_a_buyer_cannot_print_another_company_s_faktur(): void
    {
        $other = Company::factory()->create(['nama' => 'Bengkel Saingan']);

        $this->actingAs($this->buyer($other), 'customer')
            ->get($this->buyerUrl())
            ->assertNotFound();
    }

    /*
     * The two routes are separate guards, and neither may be reached with the
     * other's session — otherwise the tenancy check above is standing in front
     * of an open door.
     *
     * One session per test, deliberately. Two actingAs() calls in a single test
     * leave *both* guards holding a user, so the second request passes for the
     * wrong reason and the test reports a pass on a door it never tried.
     */

    public function test_a_staff_session_does_not_satisfy_the_customer_route(): void
    {
        $this->actingAs($this->sales)
            ->get($this->buyerUrl())
            ->assertRedirect(route('filament.portal.auth.login'));
    }

    public function test_a_buyer_session_does_not_satisfy_the_staff_route(): void
    {
        $this->actingAs($this->buyer(), 'customer')
            ->get($this->staffUrl())
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    // --- the buttons that reach it -----------------------------------------

    /**
     * The order screen offers the faktur beside the surat jalan, and the two
     * are gated on opposite roles.
     */
    public function test_the_order_page_offers_the_faktur_to_roles_that_may_see_money(): void
    {
        $this->actingAs($this->sales);

        Livewire::test(AdminViewOrder::class, ['record' => $this->order->getKey()])
            ->assertOk()
            ->assertActionVisible('faktur')
            ->assertActionHidden('surat_jalan');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->role(Role::Warehouse)->create());

        Livewire::test(AdminViewOrder::class, ['record' => $this->order->getKey()])
            ->assertOk()
            ->assertActionHidden('faktur')
            ->assertActionVisible('surat_jalan');
    }

    /**
     * An order that has not reached awaiting_payment has no invoice, and the
     * action's URL is built from one. Rendering the page must not go looking
     * for route('dokumen.faktur', null).
     */
    public function test_an_order_with_no_invoice_yet_renders_without_a_faktur_button(): void
    {
        $draft = Order::factory()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty(3)->create(['order_id' => $draft->id, 'sku' => self::SKU]);

        $this->actingAs($this->sales);

        Livewire::test(AdminViewOrder::class, ['record' => $draft->getKey()])
            ->assertOk()
            ->assertActionHidden('faktur');
    }

    /**
     * Both audiences read the same figures. A customer and the salesperson
     * discussing an invoice on the phone looking at different totals is the
     * cheapest possible way to lose an argument about money.
     */
    public function test_the_customer_copy_and_the_staff_copy_are_the_same_document(): void
    {
        $staff = $this->actingAs($this->sales)->get($this->staffUrl())->getContent();

        // A fresh session, so the staff identity does not leak into the second
        // request and quietly render the same branch twice.
        $this->app['auth']->forgetGuards();

        $customer = $this->actingAs($this->buyer(), 'customer')->get($this->buyerUrl())->getContent();

        // The toolbar's "back" link differs by referrer; the document does not.
        $strip = fn (string $html) => preg_replace('#<div class="toolbar">.*?</div>#s', '', $html);

        $this->assertSame($strip($staff), $strip($customer));
    }
}
