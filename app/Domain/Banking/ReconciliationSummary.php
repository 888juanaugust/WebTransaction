<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * The bank reconciliation statement, as one immutable set of figures.
 *
 * ```
 *   saldo per buku besar                          saldoBuku
 *     − setoran dalam perjalanan                  setoranBeredar
 *     + cek/giro beredar                          penarikanBeredar
 *     = saldo yang seharusnya di rekening koran   saldoDiharapkan
 *   dibanding saldo di rekening koran             saldoRekening
 *     = selisih                                   selisih
 * ```
 *
 * The two adjustments are easy to sign wrongly, and getting either backwards
 * produces a difference of exactly twice the amount — which looks like a
 * missing transaction rather than a sign error, and sends somebody hunting for
 * a payment that does not exist. So:
 *
 * - **Setoran dalam perjalanan** is money we recorded coming in that the bank
 *   has not seen. Our book is *higher* than the bank, so it comes off.
 * - **Cek/giro beredar** is money we recorded going out that the bank has not
 *   paid yet. Our book is *lower* than the bank, so it goes back on.
 *
 * A `selisih` of nil does not mean the books are right. It means every
 * difference between them and the bank is now explained, which is a different
 * and more useful claim.
 */
final readonly class ReconciliationSummary
{
    public function __construct(
        /** What the ledger's Bank account says at the statement date. */
        public int $saldoBuku,
        /** Recorded in, not yet on the statement. */
        public int $setoranBeredar,
        /** Recorded out, not yet on the statement. */
        public int $penarikanBeredar,
        /** What the statement's closing balance should therefore read. */
        public int $saldoDiharapkan,
        /** What it actually reads, typed off the paper. */
        public int $saldoRekening,
        /** Everything still unexplained. Nil is the only finishable state. */
        public int $selisih,
        /** How many ledger lines are still waiting to be ticked. */
        public int $belumDicentang,
    ) {}

    public function isReconciled(): bool
    {
        return $this->selisih === 0;
    }

    /**
     * Which way the unexplained difference points, in plain terms.
     *
     * Worth spelling out because the sign alone tells somebody nothing, and
     * the two cases send them to different places.
     *
     * Which way a cause points is easy to get backwards, so: money the bank
     * took and we never recorded — a charge, an administration fee, a direct
     * debit — leaves the statement *lower* than the books, so it belongs with
     * the positive case. Money the bank added and we never recorded — interest,
     * a customer who transferred without telling anybody — leaves the statement
     * *higher*. Both are closed the same way, by recording the item.
     */
    public function hint(): ?string
    {
        if ($this->isReconciled()) {
            return null;
        }

        $cause = $this->selisih > 0
            ? 'Buku besar lebih tinggi dari rekening koran. Biasanya ada biaya bank '
                .'atau debet langsung yang belum dicatat, penerimaan yang tercatat dua '
                .'kali, atau uang yang ternyata tidak pernah masuk.'
            : 'Rekening koran lebih tinggi dari buku besar. Biasanya ada bunga bank, '
                .'atau transfer pelanggan yang masuk tanpa pemberitahuan dan belum dicatat.';

        return $cause.' Kalau memang begitu, catat sebagai item rekening koran di bawah.';
    }
}
