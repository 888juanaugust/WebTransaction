<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * What a filing would contain, and what it would leave out.
 *
 * Both halves are the point. A preview that only listed what is ready would
 * let somebody file eleven of twelve fakturs and see a green tick — and the
 * twelfth is a sale we collected PPN on and did not report, which nobody
 * notices until the tax office compares our figures to the customer's.
 */
final class FakturExportPreview
{
    /**
     * @param  list<FakturRecord>  $siap
     * @param  list<FakturBlocker>  $terhalang
     */
    public function __construct(
        public readonly int $tahun,
        public readonly int $masa,
        public readonly array $siap,
        public readonly array $terhalang,
        public readonly int $sudahDiekspor = 0,
    ) {}

    public function totalDpp(): int
    {
        return array_sum(array_map(fn (FakturRecord $r) => $r->dppRupiah, $this->siap));
    }

    public function totalPpn(): int
    {
        return array_sum(array_map(fn (FakturRecord $r) => $r->ppnRupiah, $this->siap));
    }

    public function isEmpty(): bool
    {
        return $this->siap === [] && $this->terhalang === [];
    }

    /** Invoices that would be left out, counted once each. */
    public function jumlahTerhalang(): int
    {
        return count(array_unique(array_map(
            fn (FakturBlocker $b) => $b->invoice->id,
            $this->terhalang,
        )));
    }
}
