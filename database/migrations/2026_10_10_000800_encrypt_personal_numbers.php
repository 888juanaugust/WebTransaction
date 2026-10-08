<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A person's national ID, tax ID and bank account number are kept encrypted with the application key (UU PDP
 * 27/2022): the columns widen to hold the ciphertext and the values already there are encrypted in place. Safe to
 * run on data already encrypted (a value that decrypts is left as it is).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'employees' => ['nik_no', 'npwp_no', 'bank_account'],
        'vendor_bank_accounts' => ['bank_account'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->text($column)->nullable()->change();
                }
            });
            foreach (DB::table($table)->select(['id', ...$columns])->orderBy('id')->lazy() as $row) {
                $encrypted = [];
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    if ($value !== null && $value !== '' && ! self::isEncrypted($value)) {
                        $encrypted[$column] = Crypt::encryptString($value);
                    }
                }
                if ($encrypted !== []) {
                    DB::table($table)->where('id', $row->id)->update($encrypted);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach (DB::table($table)->select(['id', ...$columns])->orderBy('id')->lazy() as $row) {
                $plain = [];
                foreach ($columns as $column) {
                    if ($row->{$column} !== null && self::isEncrypted($row->{$column})) {
                        $plain[$column] = Crypt::decryptString($row->{$column});
                    }
                }
                if ($plain !== []) {
                    DB::table($table)->where('id', $row->id)->update($plain);
                }
            }
        }
    }

    private static function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
