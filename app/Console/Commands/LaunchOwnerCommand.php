<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\Role;
use App\Domain\Access\StaffRegistrar;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Mint the first real Owner — the one account bootstrapping cannot avoid.
 *
 * Every other staff account is created from the Staf screen by an Owner, with
 * the change audited to a person. The very first Owner has no person to be
 * created by, so this command is the single sanctioned exception: it works
 * only while no active Owner exists, and the moment one does, it refuses and
 * points at the screen. Production never needs a tinker session, and the
 * demo seeder — whose known passwords the launch checklist flags — never
 * needs to run outside a demo.
 *
 * The password is prompted, never passed as an option: an option lands in
 * shell history and process listings, which is a worse leak than the demo
 * seeder this command exists to replace.
 */
class LaunchOwnerCommand extends Command
{
    protected $signature = 'launch:owner
        {--nama= : Nama lengkap pemilik}
        {--email= : Alamat email untuk masuk}';

    protected $description = 'Buat akun Pemilik pertama — hanya bekerja selama belum ada Pemilik aktif';

    public function handle(StaffRegistrar $registrar): int
    {
        if (User::query()->where('role', Role::Owner->value)->where('is_active', true)->exists()) {
            $this->components->error(
                'Sudah ada Pemilik aktif. Akun staf berikutnya dibuat dari layar '
                .'Pengaturan → Staf oleh Pemilik itu — teraudit atas nama orangnya.'
            );

            return self::FAILURE;
        }

        $nama = (string) ($this->option('nama') ?: $this->ask('Nama lengkap'));
        $email = (string) ($this->option('email') ?: $this->ask('Email untuk masuk'));

        $valid = Validator::make(
            ['nama' => $nama, 'email' => $email],
            ['nama' => 'required|max:255', 'email' => 'required|email|unique:users,email'],
        );

        if ($valid->fails()) {
            foreach ($valid->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $password = (string) $this->secret('Kata sandi (min. 12 karakter)');

        if (strlen($password) < 12) {
            $this->components->error('Kata sandi minimal 12 karakter.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Ulangi kata sandi')) {
            $this->components->error('Kata sandi tidak sama.');

            return self::FAILURE;
        }

        // Actor null is honest: there is no signed-in person yet. The audit
        // row still exists, and it is the account's birth certificate.
        $registrar->create($nama, $email, Role::Owner, $password);

        $this->components->info("Pemilik {$email} dibuat.");
        $this->line('Selanjutnya, masuk di /admin dan:');
        $this->line('  1. Pengaturan → Pengaturan perusahaan — rekening, identitas, pajak');
        $this->line('  2. Pengaturan → Staf — akun untuk tiap orang, dengan perannya');
        $this->line('  3. Pengaturan → Kesiapan peluncuran — sisa daftarnya menunggu di sana');

        return self::SUCCESS;
    }
}
