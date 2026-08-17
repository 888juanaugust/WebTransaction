<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Audit\AuditLogger;
use App\Models\FakturExport;
use App\Models\FakturExportLine;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Writing back the serial numbers the tax office assigned.
 *
 * This is the half of the round trip that has no file format problem and every
 * data problem. Somebody uploads our export, Coretax assigns a nomor seri
 * faktur pajak to each faktur, and those numbers have to land on the right
 * invoices — matched by the reference we put in the file, which is our own
 * invoice number.
 *
 * The input is deliberately dumb: reference and serial, one pair per line,
 * because that is what survives a copy out of a spreadsheet. Parsing a
 * returned file properly would mean knowing that file's format, which is the
 * same thing we do not know about the outbound one.
 *
 * Three refusals, and each of them is a real mistake somebody makes:
 *
 * - **A serial already on another invoice.** Two fakturs under one number is a
 *   discrepancy the tax office finds and we do not, because nothing in our own
 *   books looks wrong. The unique index is the backstop; this is the message.
 * - **A reference that is not in this filing.** Pasting last month's return
 *   into this month's export writes numbers onto invoices that were never in
 *   it.
 * - **Changing a number already recorded.** An NSFP is issued once. A second,
 *   different one against the same invoice means either the paste is wrong or
 *   something happened at the tax office that a person needs to look at.
 *
 * Nothing here is partial. Either every pair lands or none does, so a paste
 * with one bad line does not leave half a month recorded and no way to tell
 * which half.
 */
class NsfpRecorder
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, string>  $numbers  invoice nomor => NSFP
     * @return int how many were written
     */
    public function record(FakturExport $export, array $numbers, User $actor): int
    {
        if (! $actor->role()->canExportFaktur()) {
            throw new DomainException('Anda tidak berhak mencatat nomor seri faktur pajak.');
        }

        $numbers = $this->clean($numbers);

        if ($numbers === []) {
            throw new DomainException('Tidak ada nomor seri yang bisa dibaca.');
        }

        return DB::transaction(function () use ($export, $numbers, $actor) {
            $lines = $export->lines()->with('invoice')->get()->keyBy('referensi');

            $written = 0;

            foreach ($numbers as $referensi => $nsfp) {
                $line = $lines->get($referensi);

                if ($line === null) {
                    throw new DomainException(
                        "Referensi {$referensi} tidak ada di ekspor {$export->nomor}. "
                        .'Pastikan file yang ditempel berasal dari ekspor ini.'
                    );
                }

                $this->assertNotUsedElsewhere($nsfp, $line);

                if ($line->nsfp !== null && $line->nsfp !== $nsfp) {
                    throw new DomainException(
                        "Faktur {$referensi} sudah punya nomor seri {$line->nsfp}, "
                        ."sekarang ditempel {$nsfp}. Satu faktur satu nomor — periksa dulu."
                    );
                }

                if ($line->nsfp === $nsfp) {
                    // Re-pasting the same file is harmless and common. Say
                    // nothing and count nothing.
                    continue;
                }

                $line->forceFill([
                    'nsfp' => $nsfp,
                    'nsfp_recorded_at' => now(),
                ])->save();

                $line->invoice?->forceFill(['nsfp' => $nsfp])->save();

                $written++;
            }

            $this->audit->log(
                action: 'nsfp_recorded',
                subject: $export,
                newValue: [
                    'nomor' => $export->nomor,
                    'dicatat' => $written,
                    'menunggu' => $export->refresh()->menungguNsfp(),
                ],
                actor: $actor,
            );

            return $written;
        });
    }

    /**
     * Read pairs out of pasted text.
     *
     * Tab, comma or semicolon between the two, because which one appears
     * depends on what the person copied out of and none of them is worth
     * making somebody think about.
     *
     * @return array<string, string>
     */
    public function parse(string $pasted): array
    {
        $pairs = [];

        foreach (preg_split('/\R/u', $pasted) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[\t;,]+/u', $line) ?: [];

            if (count($parts) < 2) {
                continue;
            }

            $referensi = trim($parts[0]);
            $nsfp = trim($parts[1]);

            if ($referensi !== '' && $nsfp !== '') {
                $pairs[$referensi] = $nsfp;
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, string>  $numbers
     * @return array<string, string>
     */
    private function clean(array $numbers): array
    {
        $cleaned = [];

        foreach ($numbers as $referensi => $nsfp) {
            $referensi = trim((string) $referensi);
            $nsfp = trim((string) $nsfp);

            if ($referensi !== '' && $nsfp !== '') {
                $cleaned[$referensi] = $nsfp;
            }
        }

        return $cleaned;
    }

    private function assertNotUsedElsewhere(string $nsfp, FakturExportLine $line): void
    {
        $clash = Invoice::query()
            ->where('nsfp', $nsfp)
            ->where('id', '!=', $line->invoice_id)
            ->first();

        if ($clash !== null) {
            throw new DomainException(
                "Nomor seri {$nsfp} sudah tercatat pada faktur {$clash->nomor}. "
                .'Satu nomor seri hanya untuk satu faktur.'
            );
        }
    }
}
