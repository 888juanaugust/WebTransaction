<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Billing\CreditNoteIssuer;
use App\Domain\Billing\CreditNotePoster;
use App\Domain\Billing\CreditNoteType;
use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Credit\CreditChecker;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Stock\InventoryValuation;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Nota kredit — money going back to a customer.
 *
 * Three snapshots have to be respected or the numbers lie, and each is a
 * different kind of lie:
 *
 *   - Credit at the *invoiced* price, not today's, or the refund is a number
 *     the customer never paid.
 *   - Value the returned goods at the cost they *left* at, not today's
 *     average, or a price change since the shipment invents margin.
 *   - Never credit more than shipped, or than was billed, or the document
 *     becomes a way of paying money out without a payment.
 */
class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-CN-1';

    private Warehouse $warehouse;

    private User $sales;

    private User $finance;

    private User $gudang;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('xendit.secret_key', '');

        $this->warehouse = Warehouse::factory()->create();
        $this->sales = User::factory()->sales()->create();
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->gudang = User::factory()->role(Role::Warehouse)->create();
        $this->company = Company::factory()->creditLimit(500_000_000)->create(['payment_terms_days' => 30]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);
        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);
    }

    // ------------------------------------------------------- what may be credited

    public function test_nothing_can_be_returned_before_it_ships(): void
    {
        // The invoice is raised at awaiting_payment, before the goods go out,
        // so the invoice is no evidence of delivery. The stock ledger is.
        $this->stockUp(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);

        $creditable = app(CreditNoteIssuer::class)->creditable($order->invoice);

        $this->assertCount(1, $creditable);
        $this->assertSame(0, $creditable[0]->shippedQty);
        $this->assertSame(0, $creditable[0]->remainingQty());
    }

    public function test_what_shipped_is_what_may_come_back(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $line = app(CreditNoteIssuer::class)->creditable($order->invoice)[0];

        $this->assertSame(50, $line->shippedQty);
        $this->assertSame(50, $line->remainingQty());
        $this->assertSame(60_000, $line->unitCostRupiah);
        $this->assertSame(3_000_000, $line->shippedCostRupiah);
    }

    public function test_returning_more_than_shipped_is_refused(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $note = $this->returFor($order, 51);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi yang pernah dikirim');

        app(CreditNotePoster::class)->post($note, $this->sales);
    }

    public function test_two_partial_returns_cannot_add_up_to_more_than_shipped(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $poster = app(CreditNotePoster::class);

        $poster->post($this->returFor($order, 30), $this->sales);

        $this->assertSame(20, app(CreditNoteIssuer::class)->creditable($order->invoice)[0]->remainingQty());

        $this->expectExceptionMessage('tersisa 20');

        $poster->post($this->returFor($order, 21), $this->sales);
    }

    public function test_a_potongan_on_a_line_cannot_exceed_what_that_line_was_worth(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $note = $this->potonganFor($order, 5_000_001);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi sisa nilai baris');

        app(CreditNotePoster::class)->post($note, $this->sales);
    }

    public function test_nothing_may_be_credited_beyond_the_invoice_total(): void
    {
        /*
         * A credit note larger than the invoice is not a return; it is a
         * payment out, and there is a ledger for those.
         *
         * Tested through a settlement with no order line behind it, because
         * that is the only path the per-line cap above does not already catch
         * — and it is the path where an invoice-level limit is the only limit
         * there is.
         */
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $invoice = $order->invoice;

        $note = $this->settlementFor($order, (int) $invoice->total_rupiah);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi nilai faktur');

        app(CreditNotePoster::class)->post($note, $this->sales);
    }

    public function test_several_small_credits_cannot_add_up_past_the_invoice(): void
    {
        /*
         * Checked against everything already posted, so three notes cannot do
         * what one is refused.
         *
         * The amounts below are line values, exclusive of PPN; the invoice cap
         * is on the PPN-inclusive total, which is 5,550,000 here. Two credits
         * of 2,000,000 use 4,440,000 of it and leave 1,110,000.
         */
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $poster = app(CreditNotePoster::class);

        $poster->post($this->settlementFor($order, 2_000_000), $this->sales);
        $poster->post($this->settlementFor($order, 2_000_000), $this->sales);

        $this->assertSame(1_110_000, app(CreditNoteIssuer::class)->creditableTotal($order->invoice));

        $this->expectExceptionMessage('tersisa 1.110.000');

        $poster->post($this->settlementFor($order, 1_100_000), $this->sales);
    }

    // ------------------------------------------------------------ the money

    public function test_a_return_is_credited_at_the_price_it_was_invoiced_at(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        // The price list moves after the sale. The refund must not.
        PriceListItem::query()->update(['harga' => 250_000]);

        $note = app(CreditNotePoster::class)->post($this->returFor($order, 10), $this->sales);

        // 10 of 50 at the invoiced 100,000 each, plus PPN at 11% effective.
        $this->assertSame(1_000_000, $note->subtotal_rupiah);
        $this->assertSame(110_000, $note->ppn_rupiah);
        $this->assertSame(1_110_000, $note->total_rupiah);
    }

    public function test_a_discount_given_on_the_order_is_honoured_on_the_return(): void
    {
        /*
         * Credit is apportioned from the invoiced line total, which is already
         * net of the tier discount. Recomputing quantity times list price
         * would refund more than the customer ever paid — quietly, and in the
         * customer's favour, which is the version nobody reports.
         */
        $this->company->forceFill([
            'price_tier_id' => PriceTier::factory()->discount(1_000)->create()->id,
        ])->save();

        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $line = $order->lines()->first();

        // 10% off, so the line is not 50 × the 100,000 list price.
        $this->assertSame(4_500_000, (int) $line->line_total_rupiah);

        $note = app(CreditNotePoster::class)->post($this->returFor($order, 10), $this->sales);

        $this->assertSame(900_000, $note->subtotal_rupiah);
    }

    public function test_credit_is_apportioned_from_the_line_rather_than_a_rounded_unit_price(): void
    {
        /*
         * Today these two agree exactly, because an order line's total is
         * unit price times quantity with a whole-rupiah unit price — so the
         * total always divides evenly and there is nothing to round.
         *
         * The property still worth pinning is the one that survives that
         * changing: however a line is split across returns, the parts add up
         * to the line and nothing is stranded. CLAUDE.md permits unit prices
         * at DECIMAL(18,4) "where fractional rupiah is unavoidable, rounded to
         * whole rupiah at the line level" — the first time one appears, unit
         * price times quantity starts drifting from the invoiced total and
         * apportioning does not. See CreditableLine::valueFor().
         */
        $this->priceAt(33_333, discountBps: 250);
        $this->stockUp(100, 20_000);
        $order = $this->shippedOrder(3);

        $lineTotal = (int) $order->lines()->first()->line_total_rupiah;

        $poster = app(CreditNotePoster::class);
        $first = $poster->post($this->returFor($order, 2), $this->sales);
        $second = $poster->post($this->returFor($order, 1), $this->sales);

        $this->assertSame($lineTotal, $first->subtotal_rupiah + $second->subtotal_rupiah);
        $this->assertSame(0, app(CreditNoteIssuer::class)->creditable($order->invoice)[0]->remainingValueRupiah());
    }

    public function test_returning_everything_gives_back_exactly_what_it_cost(): void
    {
        /*
         * The same property on the cost side, and it needs the same kind of
         * awkward number: two receipts at different prices leave a moving
         * average that does not divide evenly, so a partial return has no
         * exact unit cost. Apportioning from the shipment's frozen value means
         * the last return puts back the remainder rather than a rounded share,
         * and nothing is stranded in inventory.
         */
        $this->stockUp(100, 60_000);
        $this->stockUp(50, 71_111);
        $order = $this->shippedOrder(3);

        $before = app(InventoryValuation::class)->totalValue();
        $shipped = app(CreditNoteIssuer::class)->creditable($order->invoice)[0]->shippedCostRupiah;

        // No exact per-unit cost: the point of the test.
        $this->assertNotSame(0, $shipped % 3);

        $poster = app(CreditNotePoster::class);
        $poster->post($this->returFor($order, 2), $this->sales);
        $poster->post($this->returFor($order, 1), $this->sales);

        $this->assertSame($before + $shipped, app(InventoryValuation::class)->totalValue());
    }

    public function test_a_full_return_credits_the_whole_invoice_and_settles_it(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $invoice = $order->invoice;

        app(CreditNotePoster::class)->post($this->returFor($order, 50), $this->sales);

        $invoice->refresh();

        $this->assertSame(0, $invoice->amountOutstanding());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
    }

    public function test_a_potongan_moves_money_without_moving_goods(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $before = StockLevel::query()->where('sku', self::SKU)->sum('qty_on_hand');

        $note = app(CreditNotePoster::class)->post($this->potonganFor($order, 500_000), $this->sales);

        $this->assertSame(CreditNoteType::Potongan, $note->jenis);
        $this->assertSame(0, $note->hpp_rupiah);
        $this->assertSame($before, StockLevel::query()->where('sku', self::SKU)->sum('qty_on_hand'));
        $this->assertSame(555_000, $note->total_rupiah);
    }

    // ------------------------------------------------------------ the goods

    public function test_returned_goods_go_back_on_the_shelf(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $this->assertSame(50, (int) StockLevel::query()->where('sku', self::SKU)->sum('qty_on_hand'));

        app(CreditNotePoster::class)->post($this->returFor($order, 20), $this->sales);

        $this->assertSame(70, (int) StockLevel::query()->where('sku', self::SKU)->sum('qty_on_hand'));

        $movement = StockMovement::query()
            ->where('reference_type', CreditNote::class)
            ->sole();

        $this->assertSame(20, $movement->qty_signed);
        $this->assertSame('retur', $movement->reason);
    }

    public function test_goods_return_at_the_cost_they_left_at_not_todays_average(): void
    {
        /*
         * The whole reason the cost is snapshotted. Buy dearer after the
         * shipment, and the average moves; a return valued at the new average
         * would put more value back into stock than ever came out of it, and
         * book the difference as profit on goods that merely came back.
         */
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $this->stockUp(100, 90_000);          // average is now well above 60,000
        $valuedBefore = app(InventoryValuation::class)->totalValue();

        $note = app(CreditNotePoster::class)->post($this->returFor($order, 50), $this->sales);

        $this->assertSame(3_000_000, $note->hpp_rupiah);
        $this->assertSame($valuedBefore + 3_000_000, app(InventoryValuation::class)->totalValue());
    }

    public function test_a_partial_return_gives_back_exactly_its_share_of_the_cost(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $note = app(CreditNotePoster::class)->post($this->returFor($order, 17), $this->sales);

        $this->assertSame(17 * 60_000, $note->hpp_rupiah);
    }

    // ------------------------------------------------------------ the books

    public function test_a_credit_note_unwinds_the_sale_in_the_ledger(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $ledger = app(Ledger::class);

        $penjualanBefore = $ledger->balanceOf(AccountCode::PENJUALAN);
        $ppnBefore = $ledger->balanceOf(AccountCode::PPN_KELUARAN);
        $piutangBefore = $ledger->balanceOf(AccountCode::PIUTANG_USAHA);

        $note = app(CreditNotePoster::class)->post($this->returFor($order, 10), $this->sales);

        $this->assertSame($penjualanBefore - 1_000_000, $ledger->balanceOf(AccountCode::PENJUALAN));
        $this->assertSame($ppnBefore - 110_000, $ledger->balanceOf(AccountCode::PPN_KELUARAN));
        $this->assertSame($piutangBefore - 1_110_000, $ledger->balanceOf(AccountCode::PIUTANG_USAHA));
        $this->assertTrue($ledger->isBalanced());
    }

    public function test_the_cost_of_the_returned_goods_comes_out_of_cost_of_sales(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $ledger = app(Ledger::class);

        $this->assertSame(3_000_000, $ledger->balanceOf(AccountCode::HARGA_POKOK_PENJUALAN));

        app(CreditNotePoster::class)->post($this->returFor($order, 20), $this->sales);

        $this->assertSame(3_000_000 - 1_200_000, $ledger->balanceOf(AccountCode::HARGA_POKOK_PENJUALAN));
        $this->assertSame(
            app(InventoryValuation::class)->totalValue(),
            $ledger->balanceOf(AccountCode::PERSEDIAAN),
        );
    }

    public function test_a_potongan_posts_no_cost_entry_at_all(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        app(CreditNotePoster::class)->post($this->potonganFor($order, 500_000), $this->sales);

        $this->assertSame(0, JournalEntry::query()->where('jenis', JournalEntry::JENIS_HPP_RETUR)->count());
        $this->assertSame(1, JournalEntry::query()->where('jenis', JournalEntry::JENIS_NOTA_KREDIT)->count());
    }

    public function test_the_books_still_tie_to_every_subledger_after_a_return(): void
    {
        // The check that catches a posting rule being wrong: a rule that
        // credits the wrong account balances perfectly and is still wrong.
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        app(CreditNotePoster::class)->post($this->returFor($order, 20), $this->sales);

        $reconciliation = app(LedgerReconciliation::class);

        $this->assertSame([], array_map(
            fn ($c) => "{$c->nama}: buku {$c->buku} vs subledger {$c->subledger}",
            $reconciliation->discrepancies(),
        ));
        $this->assertTrue($reconciliation->isClean());
    }

    public function test_a_full_return_leaves_no_margin_behind(): void
    {
        // Everything sold came back. Revenue, cost and the receivable should
        // all be where they were before the order existed.
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $ledger = app(Ledger::class);

        app(CreditNotePoster::class)->post($this->returFor($order, 50), $this->sales);

        $this->assertSame(0, $ledger->balanceOf(AccountCode::PENJUALAN));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::HARGA_POKOK_PENJUALAN));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PPN_KELUARAN));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PIUTANG_USAHA));
        $this->assertSame(6_000_000, $ledger->balanceOf(AccountCode::PERSEDIAAN));
    }

    // ------------------------------------------------- the customer's balance

    public function test_all_three_answers_to_what_this_customer_owes_agree(): void
    {
        /*
         * The credit check, the ledger's control account and the invoice row
         * each need this figure, and each used to compute it. Credit notes
         * made it a third term, which is where three copies start disagreeing.
         */
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $invoice = $order->invoice;

        app(PaymentLedger::class)->recordManualPayment($this->company, 1_000_000, $this->finance, $invoice);
        app(CreditNotePoster::class)->post($this->returFor($order, 10), $this->sales);

        $receivables = app(OutstandingReceivables::class);
        $expected = (int) $invoice->total_rupiah - 1_000_000 - 1_110_000;

        $this->assertSame($expected, $invoice->fresh()->amountOutstanding());
        $this->assertSame($expected, $receivables->forCompany($this->company));
        $this->assertSame($expected, $receivables->total());
        $this->assertSame($expected, app(Ledger::class)->balanceOf(AccountCode::PIUTANG_USAHA));
    }

    public function test_a_return_frees_up_the_credit_it_was_using(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $before = app(CreditChecker::class)->available($this->company);

        app(CreditNotePoster::class)->post($this->returFor($order, 50), $this->sales);

        $this->assertSame($before + (int) $order->invoice->total_rupiah, app(CreditChecker::class)->available($this->company));
    }

    public function test_an_unmatched_payment_now_frees_credit_too(): void
    {
        /*
         * Not a credit note, but the same consolidation. The credit check used
         * to count only payments already matched to an invoice, so money
         * plainly in the bank freed nothing until somebody in finance got
         * round to allocating it — listed in docs/MAP.md as conservative but
         * wrong, and it also disagreed with the ledger, which never had that
         * filter.
         */
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $before = app(CreditChecker::class)->available($this->company);

        app(PaymentLedger::class)->recordManualPayment($this->company, 2_000_000, $this->finance);

        $this->assertSame($before + 2_000_000, app(CreditChecker::class)->available($this->company));
    }

    public function test_a_draft_credit_note_reduces_nothing(): void
    {
        /*
         * A draft is somebody's intention. Letting it lower a balance would
         * credit a return nobody has agreed to.
         *
         * The draft is forced to carry a total, which the posting flow never
         * does — totals are computed at posting, so a real draft sits at nil
         * and would net to nothing whether it were filtered or not. Nothing in
         * the schema stops a total appearing on a draft though, and a form
         * that pre-filled one would silently write down every customer's
         * balance. The filter is what makes that impossible; this is what
         * makes the filter provable.
         */
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $invoice = $order->invoice;

        $draft = $this->returFor($order, 50);
        $draft->forceFill(['total_rupiah' => 5_550_000, 'subtotal_rupiah' => 5_000_000])->save();

        $this->assertTrue($draft->isDraft());
        $this->assertSame((int) $invoice->total_rupiah, $invoice->fresh()->amountOutstanding());
        $this->assertSame((int) $invoice->total_rupiah, app(OutstandingReceivables::class)->total());
        $this->assertSame(0, app(CreditNoteIssuer::class)->creditedTotal($invoice));
    }

    // ------------------------------------------------------------ authority

    #[DataProvider('roles')]
    public function test_who_may_issue_a_credit_note(Role $role, bool $allowed): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $note = $this->returFor($order, 10);

        if (! $allowed) {
            $this->expectException(DomainException::class);
        }

        $posted = app(CreditNotePoster::class)->post($note, User::factory()->role($role)->create());

        $this->assertTrue($posted->isPosted());
    }

    public static function roles(): array
    {
        return [
            'sales' => [Role::Sales, true],
            'gudang' => [Role::Warehouse, false],
            // Finance confirm payments, so they must not be able to write a
            // receivable off as a return nobody witnessed.
            'keuangan' => [Role::Finance, false],
            'pemilik' => [Role::Owner, true],
        ];
    }

    public function test_whoever_confirms_payments_cannot_credit_them_away(): void
    {
        /*
         * The control this whole permission exists for: take a customer's
         * payment, keep it, then make the receivable disappear as a return.
         * Finance can do the first half and must not be able to do the second.
         */
        $finance = $this->finance->role();

        $this->assertTrue($finance->canConfirmPayment());
        $this->assertFalse($finance->canIssueCreditNote());

        $sales = $this->sales->role();
        $this->assertFalse($sales->canConfirmPayment());
        $this->assertTrue($sales->canIssueCreditNote());
    }

    public function test_a_credit_note_needs_a_reason(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('alasannya');

        app(CreditNoteIssuer::class)->draft(
            $order->invoice, CreditNoteType::Potongan, $this->sales, '  '
        );
    }

    public function test_a_retur_needs_somewhere_for_the_goods_to_go(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('gudang');

        app(CreditNoteIssuer::class)->draft(
            $order->invoice, CreditNoteType::ReturBarang, $this->sales, 'Barang rusak'
        );
    }

    public function test_posting_is_audited_with_the_reason_on_it(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        app(CreditNotePoster::class)->post($this->returFor($order, 10, 'Dua dus penyok saat kirim'), $this->sales);

        $log = AuditLog::query()->where('action', 'credit_note_posted')->sole();

        $this->assertSame('Dua dus penyok saat kirim', $log->alasan);
        $this->assertSame($this->sales->id, $log->actor_id);
    }

    // ------------------------------------------------------------ idempotency

    public function test_a_credit_note_cannot_be_posted_twice(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);
        $note = $this->returFor($order, 20);
        $poster = app(CreditNotePoster::class);

        $poster->post($note, $this->sales);

        try {
            $poster->post($note->fresh(), $this->sales);
            $this->fail('A credit note was posted twice.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sudah diposting', $e->getMessage());
        }

        $this->assertSame(70, (int) StockLevel::query()->where('sku', self::SKU)->sum('qty_on_hand'));
        $this->assertSame(1, JournalEntry::query()->where('jenis', JournalEntry::JENIS_NOTA_KREDIT)->count());
    }

    public function test_a_refused_posting_writes_nothing_at_all(): void
    {
        $this->stockUp(100, 60_000);
        $order = $this->shippedOrder(50);

        // Two lines: the first is fine, the second asks for more than shipped.
        // The first must not survive the second's refusal.
        $note = $this->returFor($order, 10);
        CreditNoteLine::factory()->create([
            'credit_note_id' => $note->id,
            'order_line_id' => $order->lines()->first()->id,
            'sku' => self::SKU,
            'urutan' => 2,
            'qty_base' => 999,
        ]);

        try {
            app(CreditNotePoster::class)->post($note->fresh(), $this->sales);
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(50, (int) StockLevel::query()->where('sku', self::SKU)->sum('qty_on_hand'));
        $this->assertSame(0, StockMovement::query()->where('reference_type', CreditNote::class)->count());
        $this->assertSame(0, JournalEntry::query()->where('jenis', JournalEntry::JENIS_NOTA_KREDIT)->count());
        $this->assertTrue($note->fresh()->isDraft());
    }

    // --- helpers ------------------------------------------------------------

    /** Re-price the catalogue, optionally with a tier discount on the customer. */
    private function priceAt(int $harga, int $discountBps = 0): void
    {
        PriceListItem::query()->update(['harga' => $harga]);

        if ($discountBps > 0) {
            $this->company->forceFill([
                'price_tier_id' => PriceTier::factory()->discount($discountBps)->create()->id,
            ])->save();
        }
    }

    private function stockUp(int $qty, int $unitCost): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }

    private function orderThrough(OrderStatus $target, int $qty): Order
    {
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
        $machine->confirm($order->refresh(), $this->sales);
        $machine->awaitPayment($order->refresh(), $this->sales);

        return $order->refresh()->load('invoice');
    }

    /** An order driven all the way to shipped, so its goods can come back. */
    private function shippedOrder(int $qty): Order
    {
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, $qty);

        $machine = app(OrderStateMachine::class);
        $machine->markPaid($order->refresh(), ['sumber' => 'test']);
        $machine->ship($order->refresh(), $this->gudang);

        return $order->refresh()->load('invoice');
    }

    private function returFor(Order $order, int $qty, string $alasan = 'Barang tidak sesuai'): CreditNote
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

    /** A potongan settled against the invoice as a whole, with no line behind it. */
    private function settlementFor(Order $order, int $amount): CreditNote
    {
        $note = app(CreditNoteIssuer::class)->draft(
            $order->invoice,
            CreditNoteType::Potongan,
            $this->sales,
            'Penyelesaian sengketa',
        );

        CreditNoteLine::factory()->create([
            'credit_note_id' => $note->id,
            'order_line_id' => null,
            'sku' => self::SKU,
            'urutan' => 1,
            'qty_base' => 0,
            'line_total_rupiah' => $amount,
        ]);

        return $note->refresh();
    }

    private function potonganFor(Order $order, int $amount): CreditNote
    {
        $note = app(CreditNoteIssuer::class)->draft(
            $order->invoice,
            CreditNoteType::Potongan,
            $this->sales,
            'Kompensasi keterlambatan kirim',
        );

        CreditNoteLine::factory()->create([
            'credit_note_id' => $note->id,
            'order_line_id' => $order->lines()->first()->id,
            'sku' => self::SKU,
            'urutan' => 1,
            'qty_base' => 0,
            'line_total_rupiah' => $amount,
        ]);

        return $note->refresh();
    }
}
