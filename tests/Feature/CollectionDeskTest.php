<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\TeamAssigner;
use App\Domain\Credit\CollectionDesk;
use App\Domain\Credit\CollectionOutcome;
use App\Domain\Credit\ContactMethod;
use App\Domain\Payments\PaymentLedger;
use App\Filament\Pages\Penagihan;
use App\Models\CollectionContact;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chasing the money.
 *
 * The feature is small; the line it must not cross is not. A collections tool
 * records what was *said* — and the moment a promise starts reducing a
 * balance, slowing a freeze or making an invoice look handled, the register
 * fills with debts everybody believes are covered and nobody is chasing. So
 * most of these tests are about what a promise deliberately does **not** do.
 *
 * The rest are about the derived answer: whether a promise was kept is read
 * from the payment ledger every time it is asked, never written down.
 */
class CollectionDeskTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private User $salesLain;

    private User $finance;

    private Company $toko;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-10 09:00:00');

        $this->sales = User::factory()->sales()->create([
            'name' => 'Andi', 'region_id' => $this->currentRegion()->id,
        ]);
        $this->salesLain = User::factory()->sales()->create([
            'name' => 'Budi', 'region_id' => $this->currentRegion()->id,
        ]);
        $this->finance = User::factory()->role(Role::Finance)->create();

        $this->toko = Company::factory()->creditLimit(500_000_000)->create([
            'nama' => 'Bengkel Jaya', 'status' => Company::STATUS_ACTIVE,
        ]);

        app(TeamAssigner::class)->assignSales(
            $this->toko, $this->sales, User::factory()->owner()->create(),
        );
    }

    // ------------------------------------------------------- what it records

    public function test_a_contact_records_who_rang_how_and_what_came_of_it(): void
    {
        $invoice = $this->overdueInvoice();

        $contact = app(CollectionDesk::class)->record(
            invoice: $invoice,
            actor: $this->sales,
            cara: ContactMethod::Whatsapp,
            hasil: CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'),
            janjiRupiah: 500_000,
            catatan: 'Kata Pak Budi hari Jumat',
        );

        $this->assertSame(ContactMethod::Whatsapp, $contact->cara);
        $this->assertSame(CollectionOutcome::JanjiBayar, $contact->hasil);
        $this->assertSame(500_000, $contact->janji_rupiah);
        $this->assertSame($this->sales->id, $contact->user_id);
        $this->assertSame($this->toko->id, $contact->company_id);
    }

    public function test_an_unreachable_shop_is_worth_recording_too(): void
    {
        // Four unanswered calls is itself the finding. Without a name for it
        // the register shows an invoice nobody chased when somebody has been
        // trying all week.
        $invoice = $this->overdueInvoice();

        $contact = app(CollectionDesk::class)->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::TidakTerhubung,
        );

        $this->assertSame(CollectionOutcome::TidakTerhubung, $contact->hasil);
        $this->assertNull($contact->janji_tanggal);
    }

    public function test_an_outcome_that_is_not_a_promise_cannot_smuggle_a_date_in(): void
    {
        // Otherwise it turns up in the promise queues, and a queue that
        // contains things nobody promised is a queue people stop reading.
        $invoice = $this->overdueInvoice();

        $contact = app(CollectionDesk::class)->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::Sengketa,
            janjiTanggal: Carbon::parse('2026-09-20'), janjiRupiah: 100_000,
        );

        $this->assertNull($contact->janji_tanggal);
        $this->assertNull($contact->janji_rupiah);
    }

    public function test_a_promise_needs_a_date_and_it_cannot_be_in_the_past(): void
    {
        $invoice = $this->overdueInvoice();
        $desk = app(CollectionDesk::class);

        try {
            $desk->record($invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar);
            $this->fail('A promise with no date is not a promise.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('tanggalnya', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah lewat/');

        $desk->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-09'),
        );
    }

    public function test_a_settled_invoice_has_nothing_to_chase(): void
    {
        $invoice = $this->overdueInvoice();
        $invoice->forceFill(['status' => Invoice::STATUS_PAID])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/tidak terbuka/');

        app(CollectionDesk::class)->record(
            $invoice->fresh(), $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'),
        );
    }

    // ------------------------------------------------ what it must NOT touch

    public function test_a_promise_moves_no_money_and_hides_no_debt(): void
    {
        /*
         * The line this whole feature sits behind. A promise is a note about
         * the future: the invoice still owes what it owed, the ledger has no
         * new entry, and the customer's exposure is unchanged. Anything else
         * and the aging report starts lying.
         */
        $invoice = $this->overdueInvoice();
        $sebelum = $invoice->amountOutstanding();

        app(CollectionDesk::class)->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'), janjiRupiah: 1_110_000,
        );

        $this->assertSame($sebelum, $invoice->fresh()->amountOutstanding());
        $this->assertSame(Invoice::STATUS_OPEN, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->paymentEntries()->count());
    }

    // --------------------------------------------------- kept, or not kept

    public function test_a_promise_not_yet_due_is_neither_kept_nor_broken(): void
    {
        $invoice = $this->overdueInvoice();

        $janji = app(CollectionDesk::class)->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'), janjiRupiah: 500_000,
        );

        // A shop that has until Friday has not broken anything on Thursday.
        $this->assertNull(app(CollectionDesk::class)->janjiDitepati($janji));
    }

    public function test_a_promise_is_kept_when_the_ledger_says_so(): void
    {
        $invoice = $this->overdueInvoice();

        $janji = app(CollectionDesk::class)->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'), janjiRupiah: 500_000,
        );

        $this->travelTo('2026-09-12 14:00:00');
        $this->pay($invoice, 500_000);

        $this->travelTo('2026-09-13 09:00:00');
        $this->assertTrue(app(CollectionDesk::class)->janjiDitepati($janji->fresh()));
    }

    public function test_a_promise_is_broken_when_the_money_did_not_arrive(): void
    {
        $invoice = $this->overdueInvoice();

        $janji = app(CollectionDesk::class)->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'), janjiRupiah: 500_000,
        );

        $this->travelTo('2026-09-13 09:00:00');

        $this->assertFalse(app(CollectionDesk::class)->janjiDitepati($janji->fresh()));
    }

    public function test_paying_less_than_promised_does_not_count_as_kept(): void
    {
        // Half of what was agreed is not the promise, and calling it kept is
        // how a shop stays off the chase list for another fortnight.
        $invoice = $this->overdueInvoice();

        $janji = app(CollectionDesk::class)->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'), janjiRupiah: 1_000_000,
        );

        $this->travelTo('2026-09-12 14:00:00');
        $this->pay($invoice, 400_000);

        $this->travelTo('2026-09-13 09:00:00');
        $this->assertFalse(app(CollectionDesk::class)->janjiDitepati($janji->fresh()));
    }

    public function test_money_that_arrived_before_the_promise_does_not_keep_it(): void
    {
        /*
         * The subtle one. A shop that paid something last week and then
         * promises more on Friday has not kept Friday's promise by having
         * paid on Monday — only what arrives after the conversation counts.
         */
        $invoice = $this->overdueInvoice();

        $this->travelTo('2026-09-09 10:00:00');
        $this->pay($invoice, 900_000);

        $this->travelTo('2026-09-10 09:00:00');
        $janji = app(CollectionDesk::class)->record(
            $invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'), janjiRupiah: 200_000,
        );

        $this->travelTo('2026-09-13 09:00:00');

        $this->assertFalse(app(CollectionDesk::class)->janjiDitepati($janji->fresh()));
    }

    public function test_the_latest_promise_is_the_one_that_stands(): void
    {
        // A shop that rings back to move Friday to Monday has moved its
        // promise, not made a second one.
        $invoice = $this->overdueInvoice();
        $desk = app(CollectionDesk::class);

        $desk->record($invoice, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-12'));

        $this->travelTo('2026-09-11 10:00:00');
        $kedua = $desk->record($invoice, $this->sales, ContactMethod::Whatsapp, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-15'));

        $this->assertSame($kedua->id, $desk->janjiBerlaku($invoice)->id);
    }

    // ------------------------------------------------------------ the queues

    public function test_the_worklist_sorts_the_morning_into_three_questions(): void
    {
        $desk = app(CollectionDesk::class);

        $hariIni = $this->overdueInvoice();
        $desk->record($hariIni, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-10'));

        $meleset = $this->overdueInvoice();
        $desk->record($meleset, $this->sales, ContactMethod::Telepon, CollectionOutcome::JanjiBayar,
            janjiTanggal: Carbon::parse('2026-09-10'));

        $sepi = $this->overdueInvoice();

        // Not yet due: belongs in nobody's morning.
        Invoice::factory()->create([
            'company_id' => $this->toko->id,
            'due_date' => now()->addDays(10)->toDateString(),
            'status' => Invoice::STATUS_OPEN,
        ]);

        $this->travelTo('2026-09-11 09:00:00');

        $daftar = $desk->worklist($this->sales);

        $this->assertSame([], $daftar['janji_hari_ini']->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$hariIni->id, $meleset->id],
            $daftar['janji_meleset']->pluck('id')->all(),
            'Yesterday\'s promises, unpaid, are today\'s broken ones.',
        );
        $this->assertSame([$sepi->id], $daftar['belum_dihubungi']->pluck('id')->all());
    }

    public function test_an_invoice_inside_its_terms_is_not_in_the_chase_queue(): void
    {
        // Training people to ignore the list is the fastest way to lose it.
        Invoice::factory()->create([
            'company_id' => $this->toko->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Invoice::STATUS_OPEN,
        ]);

        $daftar = app(CollectionDesk::class)->worklist($this->sales);

        $this->assertCount(0, $daftar['belum_dihubungi']);
    }

    public function test_a_kept_promise_leaves_the_broken_queue_by_itself(): void
    {
        $invoice = $this->overdueInvoice();

        app(CollectionDesk::class)->record($invoice, $this->sales, ContactMethod::Telepon,
            CollectionOutcome::JanjiBayar, janjiTanggal: Carbon::parse('2026-09-10'));

        $this->travelTo('2026-09-10 15:00:00');
        $this->pay($invoice, 1_110_000);

        $this->travelTo('2026-09-11 09:00:00');

        $daftar = app(CollectionDesk::class)->worklist($this->sales);

        $this->assertCount(0, $daftar['janji_meleset']);
        $this->assertCount(0, $daftar['belum_dihubungi'], 'A settled invoice is off the list entirely.');
    }

    // ------------------------------------------------------------- the seats

    public function test_a_sales_chases_their_own_customers_and_nobody_elses(): void
    {
        $invoice = $this->overdueInvoice();

        $this->assertCount(1, app(CollectionDesk::class)->worklist($this->sales)['belum_dihubungi']);
        $this->assertCount(0, app(CollectionDesk::class)->worklist($this->salesLain)['belum_dihubungi']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/bukan tanggungan Anda/');

        app(CollectionDesk::class)->record(
            $invoice, $this->salesLain, ContactMethod::Telepon, CollectionOutcome::TidakTerhubung,
        );
    }

    public function test_finance_chases_everybody(): void
    {
        $this->overdueInvoice();

        $lain = Company::factory()->creditLimit(100_000_000)->create(['status' => Company::STATUS_ACTIVE]);
        Invoice::factory()->overdue()->create([
            'company_id' => $lain->id, 'status' => Invoice::STATUS_OPEN,
        ]);

        $this->assertCount(2, app(CollectionDesk::class)->worklist($this->finance)['belum_dihubungi']);
    }

    public function test_a_seat_with_no_business_in_credit_cannot_chase(): void
    {
        $invoice = $this->overdueInvoice();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/tidak menangani penagihan/');

        app(CollectionDesk::class)->record(
            $invoice, User::factory()->role(Role::Warehouse)->create(),
            ContactMethod::Telepon, CollectionOutcome::TidakTerhubung,
        );
    }

    // ------------------------------------------------------------- the screen

    public function test_the_screen_shows_the_three_queues_and_records_a_contact(): void
    {
        $invoice = $this->overdueInvoice();

        Livewire::actingAs($this->sales)
            ->test(Penagihan::class)
            ->assertOk()
            ->assertSee('Lewat tempo, belum dihubungi')
            ->assertSee($invoice->nomor)
            ->assertSee('Belum pernah dihubungi')
            ->callAction('catat', arguments: ['invoice' => $invoice->id], data: [
                'cara' => ContactMethod::Telepon->value,
                'hasil' => CollectionOutcome::JanjiBayar->value,
                'janji_tanggal' => '2026-09-12',
                'janji_rupiah' => 500000,
                'catatan' => 'Janji Jumat',
            ])
            ->assertHasNoActionErrors();

        $contact = CollectionContact::query()->firstOrFail();

        $this->assertSame($invoice->id, $contact->invoice_id);
        $this->assertSame(500_000, $contact->janji_rupiah);
        $this->assertSame($this->sales->id, $contact->user_id);
    }

    public function test_the_screen_will_not_record_against_somebody_elses_customer(): void
    {
        // The gate is the domain's, and the screen must not be a way around
        // it: arguments come from the browser and can be edited.
        $invoice = $this->overdueInvoice();

        Livewire::actingAs($this->salesLain)
            ->test(Penagihan::class)
            ->callAction('catat', arguments: ['invoice' => $invoice->id], data: [
                'cara' => ContactMethod::Telepon->value,
                'hasil' => CollectionOutcome::TidakTerhubung->value,
            ]);

        $this->assertSame(0, CollectionContact::query()->count());
    }

    public function test_the_badge_counts_promises_not_the_whole_overdue_list(): void
    {
        /*
         * A badge that counts everything late is a number nobody can clear,
         * and a badge nobody can clear stops being read.
         */
        $this->overdueInvoice();
        $this->overdueInvoice();

        $berjanji = $this->overdueInvoice();
        app(CollectionDesk::class)->record($berjanji, $this->sales, ContactMethod::Telepon,
            CollectionOutcome::JanjiBayar, janjiTanggal: Carbon::parse('2026-09-10'));

        $this->actingAs($this->sales, 'web');

        $this->assertSame('1', Penagihan::getNavigationBadge());
    }

    // --------------------------------------------------------------- helpers

    private function overdueInvoice(): Invoice
    {
        return Invoice::factory()->overdue()->create([
            'company_id' => $this->toko->id,
            'status' => Invoice::STATUS_OPEN,
        ]);
    }

    private function pay(Invoice $invoice, int $amount): void
    {
        app(PaymentLedger::class)->recordManualPayment(
            company: $this->toko,
            amountRupiah: $amount,
            actor: $this->finance,
            invoice: $invoice,
        );
    }
}
