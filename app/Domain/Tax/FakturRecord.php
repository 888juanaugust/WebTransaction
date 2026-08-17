<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * One faktur pajak, as data, before anybody decides what a file looks like.
 *
 * This is the part of the export that is actually about our business: which
 * invoice, whose NPWP, what the DPP and PPN were per line. It is deliberately
 * free of commas, semicolons and tag names — the file format is somebody
 * else's problem, and at the time of writing it is an *unsettled* problem
 * (see FakturWriter).
 *
 * Everything here is read from the invoice and its order line snapshots.
 * Nothing is recomputed from the live price list, and nothing is recomputed
 * from the tax calculator either: the invoice already carries the figures that
 * were printed and given to the customer, and a faktur that disagrees with the
 * paper the customer holds is worse than one that is a rupiah off some ideal.
 */
final class FakturRecord
{
    /**
     * @param  list<FakturLine>  $lines
     */
    private function __construct(
        public readonly string $referensi,
        public readonly int $masaPajak,
        public readonly int $tahunPajak,
        public readonly Carbon $tanggalFaktur,
        public readonly string $kodeTransaksi,
        public readonly string $npwp,
        public readonly string $namaWajibPajak,
        public readonly string $alamatPajak,
        public readonly int $dppRupiah,
        public readonly int $ppnRupiah,
        public readonly array $lines,
    ) {}

    public static function fromInvoice(Invoice $invoice): self
    {
        $invoice->loadMissing('order.lines');

        $issued = Carbon::parse($invoice->issued_on);

        $lines = $invoice->order?->lines
            ->filter(fn ($line) => $line->isPriced())
            ->map(fn ($line) => FakturLine::fromOrderLine($line))
            ->values()
            ->all() ?? [];

        return new self(
            // Our invoice number is what Coretax echoes back beside the serial
            // it assigns, so it is the join key of the whole round trip.
            referensi: (string) $invoice->nomor,
            masaPajak: (int) $issued->month,
            tahunPajak: (int) $issued->year,
            tanggalFaktur: $issued,
            kodeTransaksi: (string) $invoice->kode_transaksi,
            // Snapshotted onto the invoice when it was issued, not read from
            // the customer record: the faktur has to show what was true then.
            npwp: (string) $invoice->npwp,
            namaWajibPajak: (string) $invoice->nama_wajib_pajak,
            alamatPajak: (string) ($invoice->alamat_pajak ?? ''),
            dppRupiah: (int) $invoice->dpp_rupiah,
            ppnRupiah: (int) $invoice->ppn_rupiah,
            lines: $lines,
        );
    }

    /**
     * The tax period this faktur belongs to, as a sortable key.
     *
     * The masa pajak follows the invoice date, not the export date — a month
     * is usually filed during the next one.
     */
    public function periode(): string
    {
        return sprintf('%04d-%02d', $this->tahunPajak, $this->masaPajak);
    }

    /**
     * Does the sum of the lines still equal the invoice header?
     *
     * It always should: both come from the same snapshots. Checked anyway
     * because the file is written from the header and the lines separately,
     * and a faktur whose parts disagree with its total is rejected on upload —
     * far better to find that here, with the invoice number in hand, than in
     * a rejection notice listing a row number.
     */
    public function isInternallyConsistent(): bool
    {
        $dpp = 0;
        $ppn = 0;

        foreach ($this->lines as $line) {
            $dpp += $line->dppRupiah;
            $ppn += $line->ppnRupiah;
        }

        return $dpp === $this->dppRupiah && $ppn === $this->ppnRupiah;
    }
}
