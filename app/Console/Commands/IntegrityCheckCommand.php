<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrity\IntegrityFinding;
use App\Domain\Integrity\LedgerIntegrity;
use Illuminate\Console\Command;

/**
 * Do the ledgers still add up? Asked from the terminal.
 *
 * The counterpart to `ops:check`, and a different question: that one asks
 * whether the box is alive, this one whether the numbers on it are true. A
 * machine can be perfectly healthy while its stock column has drifted from
 * its own kartu stok.
 *
 * Exit codes are the contract — 0 clean, 1 something drifted — so a cron line
 * pages on the number without parsing Indonesian. Worth running after a
 * restore drill and after any deploy that touched the ledgers: it is the
 * cheapest possible proof that the data survived.
 */
class IntegrityCheckCommand extends Command
{
    protected $signature = 'integritas:periksa';

    protected $description = 'Periksa keutuhan buku: stok, reservasi, nilai persediaan, akun kontrol';

    public function handle(LedgerIntegrity $integrity): int
    {
        $findings = $integrity->findings();

        if ($findings === []) {
            $this->info('UTUH   Semua buku cocok dengan kartunya.');

            return 0;
        }

        foreach ($findings as $finding) {
            $this->printFinding($finding);
        }

        $this->newLine();
        $this->error(sprintf('%d temuan. Jangan tutup buku sebelum ini dijelaskan.', count($findings)));

        return 1;
    }

    private function printFinding(IntegrityFinding $finding): void
    {
        $this->line(sprintf(
            '<error>SELISIH</error> [%s/%s] %s — %s',
            $finding->wilayah,
            $finding->pemeriksaan,
            $finding->subjek,
            $finding->temuan,
        ));
    }
}
