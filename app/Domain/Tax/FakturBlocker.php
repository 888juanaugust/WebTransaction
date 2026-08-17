<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Models\Invoice;

/**
 * A reason one invoice cannot go into the file.
 *
 * The price list importer splits its problems into blockers and notes for the
 * same reason this does: a run that half-works and says nothing is how bad
 * data gets published. Here there are only blockers. Every field on a faktur
 * is either right or it is somebody's tax return, so there is no equivalent of
 * "import anyway, annotate".
 *
 * The invoice is named in every message, because the person reading this is
 * about to go and fix a customer record and needs to know which one.
 */
final class FakturBlocker
{
    private function __construct(
        public readonly Invoice $invoice,
        public readonly string $alasan,
        public readonly string $tindakan,
    ) {}

    /** @return list<self> every reason this invoice is not ready, or none */
    public static function forInvoice(Invoice $invoice): array
    {
        $blockers = [];

        if (blank($invoice->npwp)) {
            $blockers[] = new self(
                $invoice,
                'NPWP pembeli kosong pada faktur ini.',
                'Isi NPWP di data pelanggan, lalu terbitkan ulang fakturnya.',
            );
        } elseif (! self::looksLikeNpwp((string) $invoice->npwp)) {
            /*
             * 15 digits was the old form and 16 is the current one; both are in
             * circulation since the 2024 change and both are accepted. Anything
             * else is a typo, and a typo here reports our sale against
             * somebody else's tax number.
             */
            $blockers[] = new self(
                $invoice,
                'NPWP pembeli tidak berbentuk 15 atau 16 digit: '.$invoice->npwp,
                'Perbaiki NPWP di data pelanggan, lalu terbitkan ulang fakturnya.',
            );
        }

        if (blank($invoice->nama_wajib_pajak)) {
            $blockers[] = new self(
                $invoice,
                'Nama wajib pajak kosong pada faktur ini.',
                'Isi nama wajib pajak di data pelanggan, lalu terbitkan ulang fakturnya.',
            );
        }

        if ((int) $invoice->ppn_rupiah <= 0) {
            // Nothing to report. Almost certainly an invoice raised before the
            // tax snapshot existed, or a data problem — either way, silence
            // would file a zero and call it done.
            $blockers[] = new self(
                $invoice,
                'Faktur ini tidak punya nilai PPN.',
                'Periksa order di belakangnya — kemungkinan harganya belum terkunci.',
            );
        }

        $record = FakturRecord::fromInvoice($invoice);

        if ($record->lines === []) {
            $blockers[] = new self(
                $invoice,
                'Tidak ada baris berharga di order yang mendasari faktur ini.',
                'Periksa order di belakangnya.',
            );
        } elseif (! $record->isInternallyConsistent()) {
            /*
             * The header and the lines come from the same snapshots, so this
             * should be impossible. It is checked because the file is written
             * from both separately and a faktur whose parts do not add up to
             * its total is rejected on upload — better to find it here, with
             * the invoice number in hand, than in a rejection listing a row.
             */
            $blockers[] = new self(
                $invoice,
                'Jumlah DPP/PPN per baris tidak sama dengan total faktur.',
                'Jangan diekspor. Laporkan — ini menandakan data faktur rusak.',
            );
        }

        return $blockers;
    }

    private static function looksLikeNpwp(string $npwp): bool
    {
        $digits = preg_replace('/\D+/', '', $npwp) ?? '';

        return in_array(strlen($digits), [15, 16], true);
    }
}
