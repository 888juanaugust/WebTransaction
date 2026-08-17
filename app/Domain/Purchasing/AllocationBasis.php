<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

/**
 * How a charge is spread over the goods it belongs to.
 *
 * There is no universally right answer, which is why this is a choice on the
 * document rather than a constant in the code. Both are defensible and they
 * give very different numbers on a mixed shipment, so the person entering it
 * has to say which one the charge behaves like.
 */
enum AllocationBasis: string
{
    /**
     * By value. The default, and right for anything charged as a percentage of
     * what the goods are worth: customs duty, insurance, most agent fees.
     */
    case Nilai = 'nilai';

    /**
     * By quantity. Right for anything charged by the piece or the carton —
     * some handling and inland haulage.
     *
     * Weight and volume would be better still for sea and air freight, and are
     * what a freight forwarder actually charges on. We do not hold either on a
     * product, so offering them would mean asking for a number nobody has and
     * getting a guess. Quantity is the honest approximation available here;
     * when a bearing and a leaf spring travel in the same container it will
     * flatter the heavy one, and that is worth knowing before choosing it.
     */
    case Kuantitas = 'kuantitas';

    public function label(): string
    {
        return match ($this) {
            self::Nilai => 'Nilai barang',
            self::Kuantitas => 'Jumlah satuan dasar',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Nilai => 'Barang yang lebih mahal menyerap biaya lebih besar. Cocok untuk bea masuk dan asuransi.',
            self::Kuantitas => 'Dibagi rata per satuan dasar. Cocok untuk ongkos bongkar muat dan angkut darat.',
        };
    }
}
