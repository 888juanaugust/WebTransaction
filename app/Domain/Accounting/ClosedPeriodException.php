<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Something tried to post into a month that has been closed.
 *
 * The message has to be usable by whoever hit it, which is generally not an
 * accountant — it is a member of staff entering a delivery note, being told
 * that a document with a perfectly ordinary date has been refused. So it names
 * the month, and it names the one thing they can do about it: change the date,
 * or ask for the period to be reopened.
 */
class ClosedPeriodException extends DomainException
{
    public readonly Carbon $tanggal;

    public function __construct(DateTimeInterface $tanggal, string $dokumen = '')
    {
        $this->tanggal = Carbon::parse($tanggal);

        $bulan = $this->tanggal->translatedFormat('F Y');
        $subjek = $dokumen === '' ? 'Jurnal' : $dokumen;

        parent::__construct(
            "{$subjek} bertanggal {$this->tanggal->translatedFormat('j F Y')} tidak bisa diposting: "
            ."buku bulan {$bulan} sudah ditutup. Ubah tanggal dokumen ke periode yang masih terbuka, "
            .'atau minta pemilik membuka kembali periode tersebut.'
        );
    }
}
