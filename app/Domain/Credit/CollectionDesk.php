<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Access\Role;
use App\Models\CollectionContact;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The eleven weeks between "this invoice is late" and "write it off".
 *
 * Aging already said a debt was old and the nightly sweep already told the
 * team; neither does anything about the part where somebody rings the shop and
 * the shop says Friday. That conversation was living in a notebook, which is
 * why the same customer got called twice on Tuesday and not at all in the
 * following fortnight.
 *
 * **Nothing here moves money, and nothing here is believed over the ledger.**
 * A janji bayar is a note about the future: it does not reduce a balance, does
 * not touch the credit check, does not slow the freeze, and does not make an
 * invoice look handled. Settlement remains `payment_entries` alone. That line
 * is the whole design — a collections tool that quietly adjusts what a
 * customer owes is how a register fills with debts everybody believes are
 * covered.
 *
 * So **whether a promise was kept is derived**, never stored: compare what was
 * promised against what the payment ledger actually received since the promise
 * was made. Same rule as debt aging, for the same reason — a stored "kept"
 * flag is a second opinion about settlement, and the day it disagrees with the
 * ledger somebody acts on the wrong one.
 *
 * The worklist is three questions in the order a collector asks them: who
 * promised to pay today, who promised and did not, and who is overdue with
 * nobody having called at all.
 */
class CollectionDesk
{
    public function __construct(private readonly DebtAging $aging) {}

    /**
     * Record a conversation.
     *
     * @param  int|null  $janjiRupiah  what they promised, when they promised
     */
    public function record(
        Invoice $invoice,
        User $actor,
        ContactMethod $cara,
        CollectionOutcome $hasil,
        ?Carbon $janjiTanggal = null,
        ?int $janjiRupiah = null,
        ?string $catatan = null,
        ?Carbon $dihubungiPada = null,
    ): CollectionContact {
        if ($invoice->status !== Invoice::STATUS_OPEN) {
            throw new DomainException(
                "Faktur {$invoice->nomor} sudah tidak terbuka — tidak ada yang perlu ditagih."
            );
        }

        $this->assertMayChase($actor, $invoice);

        if ($hasil->butuhJanji()) {
            if ($janjiTanggal === null) {
                throw new DomainException('Janji bayar harus menyebut tanggalnya.');
            }

            /*
             * A promise dated yesterday is not a promise, it is a note about
             * something that already failed to happen. Recording one would
             * put a row straight into the "broken" queue that nobody ever
             * agreed to.
             */
            if ($janjiTanggal->startOfDay()->lessThan(today())) {
                throw new DomainException('Tanggal janji tidak boleh sudah lewat.');
            }

            if ($janjiRupiah !== null && $janjiRupiah <= 0) {
                throw new DomainException('Jumlah yang dijanjikan harus lebih dari nol.');
            }
        } else {
            // Only one outcome carries a promise; the rest must not smuggle
            // a date in and appear in the promise queues.
            $janjiTanggal = null;
            $janjiRupiah = null;
        }

        return CollectionContact::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'user_id' => $actor->id,
            'cara' => $cara,
            'hasil' => $hasil,
            'janji_tanggal' => $janjiTanggal?->toDateString(),
            'janji_rupiah' => $janjiRupiah,
            'catatan' => $catatan,
            'dihubungi_pada' => $dihubungiPada ?? now(),
        ]);
    }

    /**
     * The promise standing on an invoice, if any.
     *
     * The latest promise wins: a shop that says Friday and then rings back to
     * say the Monday after has moved its promise, not made a second one.
     */
    public function janjiBerlaku(Invoice $invoice): ?CollectionContact
    {
        return CollectionContact::query()
            ->where('invoice_id', $invoice->id)
            ->where('hasil', CollectionOutcome::JanjiBayar->value)
            ->whereNotNull('janji_tanggal')
            ->orderByDesc('dihubungi_pada')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Was it kept? Derived from the payment ledger, never stored.
     *
     * Kept means: since the promise was made, payments reaching this invoice
     * cover what was promised — or the whole invoice was settled, which is
     * the same thing said better. A promise with no amount is kept if
     * anything at all arrived, because "I will pay Friday" with no figure
     * means the invoice.
     */
    public function janjiDitepati(CollectionContact $janji): ?bool
    {
        if (! $janji->berjanji()) {
            return null;
        }

        $invoice = $janji->invoice;

        if ($invoice === null) {
            return null;
        }

        if ((string) $invoice->status !== Invoice::STATUS_OPEN) {
            return true; // settled, whatever route it took
        }

        // Not due yet: neither kept nor broken, and saying either would be
        // wrong about a shop that has until Friday.
        if ($janji->janji_tanggal->endOfDay()->isFuture()) {
            return null;
        }

        /*
         * Strictly after the conversation. A shop that paid something earlier
         * and then promised more has not kept the new promise with the old
         * money — and the promise was made precisely because that money was
         * not enough.
         */
        /*
         * Allocations, joined back to when the money actually arrived.
         *
         * The question is "did anything reach this invoice since the
         * promise", and since a transfer can settle four fakturs at once the
         * answer is how much of it was applied *here* — not the size of the
         * entry. `paid_at` and not the allocation's own timestamp: money that
         * arrived before the call and was applied afterwards did not keep a
         * promise made after it landed.
         */
        $dibayar = (int) $invoice->allocations()
            ->join('payment_entries', 'payment_entries.id', '=', 'payment_allocations.payment_entry_id')
            ->where('payment_entries.paid_at', '>', $janji->dihubungi_pada)
            ->sum('payment_allocations.amount_rupiah');

        $dijanjikan = (int) ($janji->janji_rupiah ?? $invoice->amountOutstanding());

        return $dibayar >= $dijanjikan && $dibayar > 0;
    }

    /**
     * Promises falling due today or already past, and the overdue invoices
     * nobody has contacted — the collector's morning, in the order they
     * would work it.
     *
     * @return array{janji_hari_ini: Collection<int, Invoice>, janji_meleset: Collection<int, Invoice>, belum_dihubungi: Collection<int, Invoice>}
     */
    public function worklist(User $actor): array
    {
        $invoices = $this->chaseable($actor)->get();

        $janjiHariIni = collect();
        $janjiMeleset = collect();
        $belumDihubungi = collect();

        foreach ($invoices as $invoice) {
            $janji = $this->janjiBerlaku($invoice);

            if ($janji === null) {
                /*
                 * Only invoices actually past their due date belong in the
                 * chase queue. An invoice inside its terms is not late, and
                 * putting it here would train people to ignore the list.
                 */
                if ($this->lewatTempo($invoice)) {
                    $belumDihubungi->push($invoice);
                }

                continue;
            }

            $tanggal = $janji->janji_tanggal;

            if ($tanggal->isToday()) {
                $janjiHariIni->push($invoice->setRelation('janjiTerakhir', $janji));

                continue;
            }

            if ($tanggal->isPast() && $this->janjiDitepati($janji) === false) {
                $janjiMeleset->push($invoice->setRelation('janjiTerakhir', $janji));
            }
        }

        return [
            'janji_hari_ini' => $janjiHariIni,
            'janji_meleset' => $janjiMeleset->sortBy(fn (Invoice $i) => $i->due_date)->values(),
            'belum_dihubungi' => $belumDihubungi->sortBy(fn (Invoice $i) => $i->due_date)->values(),
        ];
    }

    /** Every contact on an invoice, most recent first. */
    public function riwayat(Invoice $invoice): Collection
    {
        return CollectionContact::query()
            ->where('invoice_id', $invoice->id)
            ->with('user')
            ->orderByDesc('dihubungi_pada')
            ->orderByDesc('id')
            ->get();
    }

    public function lewatTempo(Invoice $invoice): bool
    {
        return $invoice->due_date !== null
            && Carbon::parse($invoice->due_date)->endOfDay()->isPast();
    }

    /**
     * The open invoices this seat is responsible for chasing.
     *
     * Sales and marketing chase the customers they hold; finance and the
     * owner chase everybody. Scoped in the query rather than filtered in the
     * view, so a seat cannot widen it by asking differently.
     *
     * @return Builder<Invoice>
     */
    public function chaseable(User $actor): Builder
    {
        $query = Invoice::query()
            ->where('status', Invoice::STATUS_OPEN)
            ->with(['company'])
            ->orderBy('due_date');

        $role = $actor->role();

        if ($role === Role::Sales) {
            return $query->whereHas('company', fn (Builder $q) => $q->where('sales_user_id', $actor->id));
        }

        if ($role === Role::Marketing) {
            return $query->whereHas('company', fn (Builder $q) => $q->where('marketing_user_id', $actor->id));
        }

        return $query;
    }

    /**
     * Whose customer is this?
     *
     * Read from the customer's seat rather than from `chaseable()`, which
     * also filters on the invoice being open: asking that question of a
     * settled invoice would answer "not yours" when the truthful answer is
     * "nothing to chase", and the person reading the error would go looking
     * for the wrong problem.
     */
    private function assertMayChase(User $actor, Invoice $invoice): void
    {
        $role = $actor->role();

        if (! $role->canSeeCreditData()) {
            throw new DomainException('Peran ini tidak menangani penagihan.');
        }

        $company = $invoice->company;

        $milik = match ($role) {
            Role::Sales => (int) $company?->sales_user_id === $actor->id,
            Role::Marketing => (int) $company?->marketing_user_id === $actor->id,
            default => true,
        };

        if (! $milik) {
            throw new DomainException(
                "Faktur {$invoice->nomor} bukan tanggungan Anda — pelanggannya dipegang orang lain."
            );
        }
    }
}
