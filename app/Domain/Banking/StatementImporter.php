<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Audit\AuditLogger;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Puts an uploaded rekening koran file into the draft reconciliation.
 *
 * Parses synchronously rather than through the queue: a month of mutasi is a
 * few hundred rows, and the person uploading is sitting at the reconciliation
 * screen waiting to start matching — a queued parse would trade two seconds
 * of request time for a refresh-until-it-appears loop. The price-list
 * importer queues because workbooks run to thousands of rows; this does not.
 *
 * A file whose header cannot be found still leaves an import row — status
 * `gagal`, the reason in `catatan` — so the upload is never a thing that
 * silently didn't happen. Rows dated after the statement's closing date are
 * recorded as error lines: the closing date bounds what this statement can
 * contain, and a later row means the wrong file was exported.
 */
class StatementImporter
{
    public function __construct(
        private readonly StatementParser $parser,
        private readonly AuditLogger $audit,
    ) {}

    public function import(
        BankReconciliation $reconciliation,
        string $storedPath,
        string $originalName,
        User $actor,
    ): BankStatementImport {
        $this->assertMayImport($reconciliation, $actor);

        $contents = Storage::disk('local')->get($storedPath);

        if ($contents === null) {
            throw new DomainException('Berkas mutasi tidak ditemukan di penyimpanan.');
        }

        try {
            $parsed = $this->parser->parse($contents);
        } catch (DomainException $e) {
            $failed = BankStatementImport::create([
                'bank_reconciliation_id' => $reconciliation->id,
                'source_file_path' => $storedPath,
                'original_name' => $originalName,
                'status' => BankStatementImport::STATUS_GAGAL,
                'catatan' => $e->getMessage(),
                'created_by' => $actor->id,
            ]);

            $this->audit->log(
                action: 'bank_statement_imported',
                subject: $failed,
                newValue: ['berkas' => $originalName, 'status' => 'gagal', 'sebab' => $e->getMessage()],
                actor: $actor,
            );

            return $failed;
        }

        if ($parsed->isEmpty()) {
            throw new DomainException('Berkas terbaca tapi tidak berisi satu baris mutasi pun.');
        }

        return DB::transaction(function () use ($reconciliation, $storedPath, $originalName, $actor, $parsed) {
            $import = BankStatementImport::create([
                'bank_reconciliation_id' => $reconciliation->id,
                'source_file_path' => $storedPath,
                'original_name' => $originalName,
                'status' => BankStatementImport::STATUS_SELESAI,
                'created_by' => $actor->id,
            ]);

            $errors = 0;

            foreach ($parsed->rows as $row) {
                $afterClosing = $row['tanggal'] > $reconciliation->tanggal_rekening->toDateString();

                if ($afterClosing) {
                    $errors++;
                }

                BankStatementLine::create([
                    'bank_statement_import_id' => $import->id,
                    'urutan' => $row['urutan'],
                    'tanggal' => $row['tanggal'],
                    'uraian' => mb_substr($row['uraian'], 0, 500),
                    'arah' => $row['arah'],
                    'amount_rupiah' => $row['amount_rupiah'],
                    'saldo_rupiah' => $row['saldo_rupiah'],
                    'status' => $afterClosing ? BankStatementLine::STATUS_ERROR : BankStatementLine::STATUS_BELUM,
                    'keterangan' => $afterClosing
                        ? 'Bertanggal setelah tanggal rekening koran — berkas yang salah?'
                        : null,
                ]);
            }

            foreach ($parsed->errors as $error) {
                $errors++;

                BankStatementLine::create([
                    'bank_statement_import_id' => $import->id,
                    'urutan' => $error['urutan'],
                    'tanggal' => null,
                    'uraian' => mb_substr($error['uraian'], 0, 500),
                    'arah' => null,
                    'amount_rupiah' => null,
                    'saldo_rupiah' => null,
                    'status' => BankStatementLine::STATUS_ERROR,
                    'keterangan' => $error['sebab'],
                ]);
            }

            $import->forceFill([
                'jumlah_baris' => count($parsed->rows) + count($parsed->errors),
                'jumlah_error' => $errors,
            ])->save();

            $this->audit->log(
                action: 'bank_statement_imported',
                subject: $import,
                newValue: [
                    'rekonsiliasi' => $reconciliation->nomor,
                    'berkas' => $originalName,
                    'jumlah_baris' => $import->jumlah_baris,
                    'jumlah_error' => $errors,
                ],
                actor: $actor,
            );

            return $import->refresh();
        });
    }

    private function assertMayImport(BankReconciliation $reconciliation, User $actor): void
    {
        if (! $actor->role()->canReconcileBank()) {
            throw new DomainException('Anda tidak berhak melakukan rekonsiliasi bank.');
        }

        if (! $reconciliation->isDraft()) {
            throw new DomainException(
                "Rekonsiliasi {$reconciliation->nomor} sudah selesai — mutasi diimpor ke draf."
            );
        }
    }
}
