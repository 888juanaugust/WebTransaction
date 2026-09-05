<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Money;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\PaymentEntry;
use Illuminate\Support\Carbon;

/**
 * Rekening koran pelanggan — one customer's account, movement by movement.
 *
 * The document a B2B customer asks for before they pay. Their bookkeeper has a
 * figure, we have a figure, and the two disagree; this is what settles it. Every
 * other report here answers a question for us — this one is written to be sent
 * out, which is why it reads as a running account rather than a table of
 * balances.
 *
 * **It closes on what the invoices say, one row at a time.** Billed, less paid,
 * less credited, in date order — the same three things
 * `OutstandingReceivables` counts and the same rule the ageing report is built
 * on, rather than two rules that happen to agree today.
 *
 * **Two things are named in a note rather than shown as a credit**, and both
 * for the same reason: neither has been put against any invoice on this page,
 * so there is no line here either of them reduced.
 *
 * A **giro** is the decision meeting a customer face to face. Somebody who
 * handed over a postdated cheque believes they have paid; the invoice stays
 * open until it clears. Leaving it out entirely is what starts the argument,
 * and crediting it is what makes the books claim money that is not in the bank.
 *
 * A **deposit still held** is the mirror of that — money genuinely in our
 * account that no invoice has claimed. It is named so the customer can see we
 * have it, and it is not netted off, so the balance keeps meaning "what these
 * invoices come to".
 *
 * Credit exposure differs from this closing balance by exactly the deposits
 * held: we cannot lose cash we already have, so `forCompany()` subtracts them
 * and this does not. That is the same asymmetry as giro, in the other
 * direction.
 *
 * **Every read here crosses regions**, because it is filtered to one customer
 * and a customer is not a region. Since the multi-warehouse split their
 * fakturs book wherever the goods shipped from, and a statement written from
 * one region's books shows the customer part of what they owe — a document
 * sent out under our name, understating their debt, in writing. That is the
 * one direction of this error nobody at this end ever notices.
 */
class CustomerStatement
{
    public function __construct(
        private readonly OutstandingReceivables $receivables,
    ) {}

    public function build(Company $company, Period $period): ReportTable
    {
        $opening = $this->balanceBefore($company, $period->from);
        $movements = $this->movements($company, $period);

        $rows = [[
            'tanggal' => null,
            'dokumen' => '',
            'keterangan' => 'Saldo awal',
            'tagihan' => null,
            'pembayaran' => null,
            'saldo' => $opening,
        ]];

        $balance = $opening;

        foreach ($movements as $movement) {
            $balance += $movement['delta'];

            $rows[] = [
                'tanggal' => $movement['tanggal'],
                'dokumen' => $movement['dokumen'],
                'keterangan' => $movement['keterangan'],
                // Two columns rather than one signed one: this goes to somebody
                // else's bookkeeper, and a column of negative numbers is read
                // wrongly at least once by somebody.
                'tagihan' => $movement['delta'] > 0 ? $movement['delta'] : null,
                'pembayaran' => $movement['delta'] < 0 ? -$movement['delta'] : null,
                'saldo' => $balance,
            ];
        }

        return new ReportTable(
            judul: "Rekening koran — {$company->nama}",
            period: $period,
            columns: [
                ReportColumn::date('tanggal', 'Tanggal'),
                ReportColumn::text('dokumen', 'Dokumen'),
                ReportColumn::text('keterangan', 'Keterangan'),
                ReportColumn::money('tagihan', 'Tagihan'),
                ReportColumn::money('pembayaran', 'Pembayaran'),
                ReportColumn::money('saldo', 'Saldo'),
            ],
            rows: $rows,
            totals: [
                'keterangan' => 'Saldo akhir',
                'tagihan' => null,
                'pembayaran' => null,
                'saldo' => $balance,
            ],
            catatan: $this->catatan($company, $period, $balance),
        );
    }

    /**
     * What they owed the moment before the period opened.
     *
     * The same three sources as the movements, with the same rules — an
     * opening balance computed a second way is how a statement ends up not
     * agreeing with the one issued last month.
     */
    private function balanceBefore(Company $company, Carbon $from): int
    {
        $before = $from->copy()->startOfDay();

        $invoiced = (int) Invoice::query()
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->whereDate('issued_on', '<', $before)
            ->sum('total_rupiah');

        $paid = (int) PaymentEntry::query()
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->where('paid_at', '<', $before)
            ->sum('amount_rupiah');

        $credited = (int) CreditNote::query()
            ->withoutGlobalScope('region')
            ->posted()
            ->where('company_id', $company->id)
            ->whereDate('tanggal', '<', $before)
            ->sum('total_rupiah');

        return $invoiced - $paid - $credited;
    }

    /**
     * Everything that moved the balance inside the window, oldest first.
     *
     * @return list<array{tanggal: Carbon, dokumen: string, keterangan: string, delta: int}>
     */
    private function movements(Company $company, Period $period): array
    {
        $from = $period->from->copy()->startOfDay();
        $to = $period->to->copy()->endOfDay();

        $movements = [];

        $invoices = Invoice::query()
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->get();

        foreach ($invoices as $invoice) {
            $movements[] = [
                'tanggal' => Carbon::parse($invoice->issued_on),
                'dokumen' => (string) $invoice->nomor,
                'keterangan' => 'Faktur penjualan'.($invoice->due_date
                    ? ' — jatuh tempo '.Carbon::parse($invoice->due_date)->format('d/m/Y')
                    : ''),
                'delta' => (int) $invoice->total_rupiah,
            ];
        }

        $payments = PaymentEntry::query()
            ->withoutGlobalScope('region')
            ->with(['invoice', 'allocations.invoice'])
            ->where('company_id', $company->id)
            ->whereBetween('paid_at', [$from, $to])
            ->get();

        foreach ($payments as $payment) {
            $amount = (int) $payment->amount_rupiah;

            $movements[] = [
                'tanggal' => Carbon::parse($payment->paid_at),
                'dokumen' => implode(', ', $this->paymentDocuments($payment)),
                'keterangan' => $this->paymentLabel($payment),
                // Reversals are stored as negative payment rows, so a bounced
                // transfer comes back as a charge without any special case.
                'delta' => -$amount,
            ];
        }

        $notes = CreditNote::query()
            ->withoutGlobalScope('region')
            ->posted()
            ->where('company_id', $company->id)
            ->whereBetween('tanggal', [$from->toDateString(), $to->toDateString()])
            ->get();

        foreach ($notes as $note) {
            $movements[] = [
                'tanggal' => Carbon::parse($note->tanggal),
                'dokumen' => (string) $note->nomor,
                'keterangan' => 'Nota kredit'.($note->alasan ? " — {$note->alasan}" : ''),
                'delta' => -(int) $note->total_rupiah,
            ];
        }

        usort($movements, fn (array $a, array $b) => [$a['tanggal']->timestamp, $a['dokumen']]
            <=> [$b['tanggal']->timestamp, $b['dokumen']]);

        return $movements;
    }

    /**
     * Which fakturs one payment was put against.
     *
     * From the allocations, not from `invoice_id`. The column on the entry is
     * the one-bill shorthand and is null the moment a transfer covers four
     * fakturs — which is the ordinary month end for a customer on 30-day
     * terms. Read from there, the Dokumen column on this statement went blank
     * on exactly the payments the customer is most likely to ask about.
     *
     * @return list<string>
     */
    private function paymentDocuments(PaymentEntry $payment): array
    {
        $nomor = $payment->allocations
            ->map(fn ($alokasi) => (string) ($alokasi->invoice?->nomor ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($nomor !== []) {
            return $nomor;
        }

        return $payment->invoice?->nomor ? [(string) $payment->invoice->nomor] : [];
    }

    /**
     * What to call one line of money coming in.
     *
     * "Belum dicocokkan" is decided on the arithmetic — how much of the entry
     * no faktur has claimed — rather than on whether anybody named a faktur.
     * The old test was `invoice_id === null`, which called a transfer spread
     * across four bills *unmatched* and called a Rp 50.000.000 transfer
     * pointed at a Rp 12.000.000 bill *matched*. Both are wrong to say to a
     * customer, and the second is the one that starts an argument, because
     * they can see the Rp 38.000.000 that nothing on this page accounts for.
     */
    private function paymentLabel(PaymentEntry $payment): string
    {
        if ((int) $payment->amount_rupiah < 0) {
            return 'Pembalikan pembayaran'.($payment->catatan ? " — {$payment->catatan}" : '');
        }

        $dialokasikan = (int) $payment->allocations->sum('amount_rupiah');
        $sisa = (int) $payment->amount_rupiah - $dialokasikan;

        if ($dialokasikan <= 0) {
            return 'Pembayaran diterima — belum dicocokkan ke faktur';
        }

        if ($sisa > 0) {
            return sprintf(
                'Pembayaran diterima — %s belum dicocokkan ke faktur',
                Money::format($sisa),
            );
        }

        $faktur = count($this->paymentDocuments($payment));

        return $faktur > 1
            ? "Pembayaran diterima — dibagi ke {$faktur} faktur"
            : 'Pembayaran diterima';
    }

    /**
     * @return list<string>
     */
    private function catatan(Company $company, Period $period, int $closing): array
    {
        $catatan = [];

        $giro = $this->receivables->giroHeld($company->id);

        if ($giro > 0) {
            /*
             * The line that stops an argument. From the customer's side the
             * cheque is paid; from ours the invoice is open until it clears.
             * Saying both here is cheaper than saying it on the phone.
             */
            $catatan[] = sprintf(
                'Termasuk %s yang dijamin bilyet giro dan belum cair. Faktur tetap '
                .'terbuka sampai gironya cair.',
                Money::format($giro),
            );
        }

        $uangMuka = $this->receivables->depositsHeld($company);

        if ($uangMuka > 0) {
            /*
             * Not netted off the balance, for the same reason a deposit is not
             * a payment: it has not been put against any invoice on this
             * statement, so no line here would be the one it reduced. Saying
             * the figure and saying it is theirs is what stops the customer
             * reading the closing balance as money they still have to find.
             */
            $catatan[] = sprintf(
                'Kami masih memegang uang muka %s dari Anda yang belum dipakai untuk faktur '
                .'mana pun. Saldo di atas belum dikurangi jumlah ini.',
                Money::format($uangMuka),
            );
        }

        /*
         * How much of their money is sitting on no faktur — summed as the
         * remainder of each entry, not as the whole of the entries nobody
         * named a faktur on. The old shape asked the question `invoice_id IS
         * NULL` answers, which since the allocation ledger is a different
         * question: it counted the *whole* of a transfer spread across four
         * bills as unmatched, and counted nothing at all of a transfer aimed
         * at one bill it was twice the size of.
         */
        $unmatched = (int) PaymentEntry::query()
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->where('kind', PaymentEntry::KIND_PAYMENT)
            ->where('paid_at', '<=', $period->to->copy()->endOfDay())
            ->selectRaw('COALESCE(SUM(payment_entries.amount_rupiah - COALESCE((
                SELECT SUM(amount_rupiah) FROM payment_allocations
                WHERE payment_allocations.payment_entry_id = payment_entries.id
            ), 0)), 0) AS sisa')
            ->value('sisa');

        if ($unmatched > 0) {
            $catatan[] = sprintf(
                'Termasuk %s pembayaran yang sudah kami terima tetapi belum dicocokkan '
                .'ke faktur tertentu. Saldonya sudah dikurangi.',
                Money::format($unmatched),
            );
        }

        if ($closing < 0) {
            $catatan[] = 'Saldo negatif berarti pembayaran melebihi tagihan — kelebihannya '
                .'menjadi kredit untuk pesanan berikutnya.';
        }

        return $catatan;
    }
}
