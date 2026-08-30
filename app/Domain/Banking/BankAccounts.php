<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Audit\AuditLogger;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The register of rekening the business runs.
 *
 * Opening one mints a GL account in the 1-11xx range beside the original
 * Bank (1-1100), so the neraca shows each balance on its own line and the
 * trial balance keeps rolling them up under ASET without any new plumbing —
 * an account code is all the ledger ever needed.
 *
 * Owner only, and audited: which bank accounts exist, and which one money
 * lands in by default, are exactly the facts a fraud would quietly change.
 */
class BankAccounts
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Where money goes when nobody chooses — and what every posting before
     * multi-bank existed already used.
     */
    public function default(): BankAccount
    {
        $default = BankAccount::query()->aktif()->where('is_default', true)->first()
            ?? BankAccount::query()->aktif()->orderBy('id')->first();

        if ($default === null) {
            throw new DomainException(
                'Tidak ada rekening bank yang terdaftar — jalankan migrasi, atau buka satu di Pengaturan.'
            );
        }

        return $default;
    }

    public function open(
        string $nama,
        string $bank,
        string $nomor,
        string $atasNama,
        User $actor,
    ): BankAccount {
        $this->assertOwner($actor);

        if (trim($nama) === '' || trim($bank) === '' || trim($nomor) === '') {
            throw new DomainException('Nama, bank, dan nomor rekening wajib diisi.');
        }

        return DB::transaction(function () use ($nama, $bank, $nomor, $atasNama, $actor) {
            $account = Account::create([
                'kode' => $this->nextCode(),
                'nama' => 'Bank — '.trim($nama),
                'tipe' => Account::byCode(AccountCode::BANK)->tipe,
                'saldo_normal' => Account::byCode(AccountCode::BANK)->saldo_normal,
                'dapat_diposting' => true,
                'parent_id' => Account::byCode(AccountCode::BANK)->parent_id,
                'aktif' => true,
                'catatan' => "Rekening {$bank} {$nomor}, dibuka dari Pengaturan.",
            ]);

            $rekening = BankAccount::create([
                'nama' => trim($nama),
                'bank' => trim($bank),
                'nomor' => trim($nomor),
                'atas_nama' => trim($atasNama),
                'account_id' => $account->id,
                'is_default' => false,
                'aktif' => true,
                'created_by' => $actor->id,
            ]);

            $this->audit->log(
                action: 'bank_account_opened',
                subject: $rekening,
                newValue: [
                    'nama' => $rekening->nama,
                    'bank' => $rekening->bank,
                    'nomor' => $rekening->nomor,
                    'akun' => $account->kode,
                ],
                actor: $actor,
            );

            return $rekening;
        });
    }

    /** Move the default — where unchosen money lands from now on. */
    public function setDefault(BankAccount $rekening, User $actor): void
    {
        $this->assertOwner($actor);

        if (! $rekening->aktif) {
            throw new DomainException('Rekening nonaktif tidak bisa jadi bawaan.');
        }

        $lama = $this->default();

        if ($lama->id === $rekening->id) {
            return;
        }

        DB::transaction(function () use ($rekening, $lama, $actor) {
            BankAccount::query()->where('is_default', true)->update(['is_default' => false]);
            $rekening->forceFill(['is_default' => true])->save();

            $this->audit->log(
                action: 'bank_account_default_changed',
                subject: $rekening,
                oldValue: ['rekening' => $lama->label()],
                newValue: ['rekening' => $rekening->label()],
                actor: $actor,
            );
        });
    }

    /**
     * The next free code beside 1-1100. The 1-11xx block belongs to bank
     * accounts by construction — the next fixed code is 1-1200.
     */
    private function nextCode(): string
    {
        for ($n = 1; $n <= 99; $n++) {
            $kode = sprintf('1-11%02d', $n);

            if (! Account::query()->where('kode', $kode)->exists()) {
                return $kode;
            }
        }

        throw new DomainException('Blok kode akun bank (1-1101..1-1199) sudah penuh.');
    }

    private function assertOwner(User $actor): void
    {
        if ($actor->role() !== Role::Owner) {
            throw new DomainException('Hanya Pemilik yang membuka rekening bank.');
        }
    }
}
