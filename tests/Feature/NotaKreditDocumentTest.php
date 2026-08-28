<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\TeamAssigner;
use App\Domain\Billing\CreditNoteIssuer;
use App\Domain\Billing\CreditNotePoster;
use App\Domain\Billing\CreditNoteType;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\CustomerUser;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
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
 * The printed nota kredit, and who may reach it.
 *
 * The access rules are inverted from the permission that creates one, and that
 * is deliberate rather than an oversight: Finance cannot raise a credit note
 * because they confirm payments, but they very much need to read one, since it
 * is why a debt they are chasing got smaller.
 */
class NotaKreditDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-NK-1';

    private Warehouse $warehouse;

    private User $sales;

    private User $finance;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('xendit.secret_key', '');

        $this->warehouse = Warehouse::factory()->create();
        $this->sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->company = Company::factory()->creditLimit(500_000_000)->create(['payment_terms_days' => 30]);

        // Returs are filed by the sales who holds the store.
        app(TeamAssigner::class)->assignSales(
            $this->company,
            $this->sales,
            User::factory()->owner()->create(),
        );

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);
        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'description' => 'Shock absorber depan',
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);
    }

    // ------------------------------------------------------------- the page

    public function test_the_document_shows_the_figures_and_the_reason(): void
    {
        $note = $this->postedNote(10, 'Dua dus penyok saat kirim');

        $this->actingAs($this->sales, 'web')
            ->get(route('dokumen.nota-kredit', $note))
            ->assertOk()
            ->assertSee($note->nomor)
            ->assertSee($note->invoice->nomor)
            ->assertSee('Dua dus penyok saat kirim')
            ->assertSee('Shock absorber depan')
            ->assertSee('Rp 100.000')     // harga satuan, as invoiced
            ->assertSee('Rp 1.000.000')   // nilai kredit
            ->assertSee('Rp 110.000')     // PPN
            ->assertSee('Rp 1.110.000');  // total
    }

    public function test_the_document_says_it_is_not_a_payment(): void
    {
        // A nota kredit mistaken for an invoice gets paid; mistaken for a
        // receipt, it gets treated as money already in.
        $note = $this->postedNote(10);

        $this->actingAs($this->sales, 'web')
            ->get(route('dokumen.nota-kredit', $note))
            ->assertOk()
            ->assertSee('Bukan penerimaan uang tunai')
            ->assertSee('bukan Faktur Pajak');
    }

    public function test_the_amount_is_spelled_out_in_words(): void
    {
        $note = $this->postedNote(10);

        $this->actingAs($this->sales, 'web')
            ->get(route('dokumen.nota-kredit', $note))
            ->assertOk()
            ->assertSee('satu juta seratus sepuluh ribu rupiah');
    }

    public function test_a_draft_cannot_be_printed(): void
    {
        // Every figure on a credit note is decided at posting. Printing a
        // draft hands somebody a document whose totals are all nil.
        $order = $this->shippedOrder(50);
        $draft = $this->draftFor($order, 10);

        $this->actingAs($this->sales, 'web')
            ->get(route('dokumen.nota-kredit', $draft))
            ->assertNotFound();
    }

    // ------------------------------------------------------------- who sees it

    #[DataProvider('staffRoles')]
    public function test_who_may_read_the_staff_copy(Role $role, bool $allowed): void
    {
        $note = $this->postedNote(10);

        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(route('dokumen.nota-kredit', $note));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function staffRoles(): array
    {
        return [
            'sales' => [Role::Sales, true],
            // Inventori verify returs now, so the register is theirs to
            // open — the price columns come with the job since 2026-08.
            'gudang' => [Role::Warehouse, true],
            // Finance cannot raise one and must be able to read one.
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
        ];
    }

    public function test_a_buyer_can_print_their_own_credit_note(): void
    {
        $note = $this->postedNote(10);
        $buyer = CustomerUser::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($buyer, 'customer')
            ->get(route('portal.dokumen.nota-kredit', $note))
            ->assertOk()
            ->assertSee($note->nomor);
    }

    public function test_another_companys_credit_note_is_a_404_not_a_403(): void
    {
        // A 403 confirms the document exists, and the number is guessable.
        $note = $this->postedNote(10);
        $stranger = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAs($stranger, 'customer')
            ->get(route('portal.dokumen.nota-kredit', $note))
            ->assertNotFound();
    }

    public function test_a_logged_out_visitor_gets_nothing(): void
    {
        $note = $this->postedNote(10);

        $this->get(route('dokumen.nota-kredit', $note))->assertRedirect();
        $this->get(route('portal.dokumen.nota-kredit', $note))->assertRedirect();
    }

    // ------------------------------------------------------------- the screen

    #[DataProvider('staffRoles')]
    public function test_who_may_open_the_credit_note_list(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, CreditNoteResource::canViewAny());
    }

    public function test_finance_may_read_credit_notes_but_never_raise_one(): void
    {
        /*
         * The whole control in one assertion. Finance confirm payments; if they
         * could also credit a receivable away, a payment could be pocketed and
         * the debt written off as a return nobody witnessed.
         */
        $this->actingAs($this->finance);

        $this->assertTrue(CreditNoteResource::canViewAny());
        $this->assertFalse(CreditNoteResource::canCreate());

        $this->actingAs($this->sales);

        $this->assertTrue(CreditNoteResource::canViewAny());
        $this->assertTrue(CreditNoteResource::canCreate());
    }

    public function test_finance_is_not_even_offered_the_create_button(): void
    {
        /*
         * Not a duplicate of the permission test above. The route already
         * refused Finance with a 403 — and Filament still rendered "Nota
         * kredit baru" as a live link straight to it, which reads as a
         * feature that is temporarily unavailable rather than one that is not
         * theirs. Asserting on the rendered page is the only way that shows up.
         */
        $this->postedNote(10);

        Livewire::actingAs($this->finance)
            ->test(ListCreditNotes::class)
            ->assertOk()
            ->assertDontSee('Nota kredit baru');

        Livewire::actingAs($this->sales)
            ->test(ListCreditNotes::class)
            ->assertSee('Nota kredit baru');
    }

    public function test_the_create_route_refuses_finance_as_well_as_hiding_it(): void
    {
        // Hiding a button is not access control.
        $this->actingAs($this->finance, 'web')
            ->get(CreditNoteResource::getUrl('create'))
            ->assertForbidden();

        $this->actingAs($this->sales, 'web')
            ->get(CreditNoteResource::getUrl('create'))
            ->assertOk();
    }

    public function test_a_posted_note_cannot_be_edited_by_anybody(): void
    {
        $note = $this->postedNote(10);
        $owner = User::factory()->role(Role::Owner)->create();

        $this->actingAs($owner);

        $this->assertFalse(CreditNoteResource::canEdit($note));
        $this->assertFalse(CreditNoteResource::canDelete($note));
    }

    public function test_a_draft_can_still_be_corrected(): void
    {
        $order = $this->shippedOrder(50);
        $draft = $this->draftFor($order, 10);

        $this->actingAs($this->sales);

        $this->assertTrue(CreditNoteResource::canEdit($draft));
    }

    // --- helpers ------------------------------------------------------------

    private function postedNote(int $qty, string $alasan = 'Barang tidak sesuai'): CreditNote
    {
        $order = $this->shippedOrder(50);

        // Posting a retur is Inventori's verification, never the drafter's.
        return app(CreditNotePoster::class)->post($this->draftFor($order, $qty, $alasan), User::factory()->role(Role::Warehouse)->create());
    }

    private function draftFor(Order $order, int $qty, string $alasan = 'Barang tidak sesuai'): CreditNote
    {
        $note = app(CreditNoteIssuer::class)->draft(
            $order->invoice,
            CreditNoteType::ReturBarang,
            $this->sales,
            $alasan,
            warehouseId: $this->warehouse->id,
        );

        CreditNoteLine::factory()->create([
            'credit_note_id' => $note->id,
            'order_line_id' => $order->lines()->first()->id,
            'sku' => self::SKU,
            'urutan' => 1,
            'qty_base' => $qty,
        ]);

        return $note->refresh();
    }

    private function shippedOrder(int $qty): Order
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces(200, 60_000)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);
        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextOrderNumber(),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $this->sales);
        $machine->confirm($order->refresh(), $this->approver());
        $machine->awaitPayment($order->refresh(), $this->sales);
        $machine->markPaid($order->refresh(), ['sumber' => 'test']);
        $machine->ship($order->refresh(), User::factory()->role(Role::Warehouse)->create());

        return $order->refresh()->load('invoice');
    }
}
