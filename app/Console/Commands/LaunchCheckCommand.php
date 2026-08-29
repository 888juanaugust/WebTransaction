<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Launch\LaunchCheck;
use App\Domain\Launch\LaunchCheckKind;
use App\Domain\Launch\LaunchReadiness;
use Illuminate\Console\Command;

/**
 * The launch checklist, run from the server it would launch on.
 *
 * The screen already computes all of this, but the deploy runbook is a
 * terminal session: the person who just finished `.env` should not have to
 * open a browser and sign in as the Owner to learn the bank account is
 * still the placeholder. Same checks, same order, one exit code — non-zero
 * while anything blocks, so a deploy script can refuse to continue.
 *
 * Prints only. Attesting still happens on the screen, where the statement
 * is recorded against a person.
 */
class LaunchCheckCommand extends Command
{
    protected $signature = 'launch:check';

    protected $description = 'Jalankan checklist kesiapan peluncuran dan keluar non-nol bila ada yang belum beres';

    public function handle(LaunchReadiness $readiness): int
    {
        foreach ($readiness->checks() as $check) {
            $this->printCheck($check);
        }

        $outstanding = $readiness->outstanding();

        $this->newLine();

        if ($outstanding === 0) {
            $this->info('Semua item terpenuhi. Lihat docs/DEPLOY.md untuk langkah peluncuran selanjutnya.');

            return self::SUCCESS;
        }

        $this->error("{$outstanding} item belum beres. Item attestasi ditandatangani lewat layar Kesiapan peluncuran oleh Owner.");

        return self::FAILURE;
    }

    private function printCheck(LaunchCheck $check): void
    {
        $status = $check->lulus ? '<info>LULUS</info>' : '<error>BELUM</error>';
        $jenis = $check->jenis === LaunchCheckKind::Otomatis ? 'otomatis' : 'attestasi';

        $this->line(sprintf('%s  [%s] %s', $status, $jenis, $check->judul));

        if (! $check->lulus && $check->temuan !== null) {
            $this->line("        {$check->temuan}");
        }

        if (! $check->lulus && $check->tindakan !== null) {
            $this->line("        → {$check->tindakan}");
        }
    }
}
