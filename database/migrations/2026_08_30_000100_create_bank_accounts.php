<?php

declare(strict_types=1);

use App\Domain\Accounting\AccountCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More than one bank account — the last big item on the not-built list.
 *
 * Every rekening the business runs gets a row here and its own GL account,
 * so the neraca shows each balance separately and reconciliation proves each
 * account against its own rekening koran. The first row is backfilled onto
 * the EXISTING Bank account (1-1100): every journal line ever posted to Bank
 * belongs to that rekening, which keeps all history true without touching a
 * single posted entry. New accounts mint new 1-11xx GL codes.
 *
 * Money-side tables learn which rekening a payment hit. Null means "the
 * default at the time", which for all existing rows is the backfilled first
 * account — the same 1-1100 their journals already sit on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();

            $table->string('nama', 60);          // label people use: "BCA operasional"
            $table->string('bank', 30);           // the institution
            $table->string('nomor', 40);
            $table->string('atas_nama', 120);

            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->boolean('is_default')->default(false);
            $table->boolean('aktif')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('account_id');
        });

        Schema::table('payment_entries', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()
                ->constrained('bank_accounts')->restrictOnDelete();
        });

        Schema::table('supplier_payment_entries', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()
                ->constrained('bank_accounts')->restrictOnDelete();
        });

        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()
                ->constrained('bank_accounts')->restrictOnDelete();

            // One statement per date used to be the rule; now it is one per
            // date PER REKENING — two accounts both issue an end-of-month
            // statement, and both must be reconcilable.
            $table->dropUnique('bank_reconciliations_tanggal_rekening_unique');
            $table->unique(['bank_account_id', 'tanggal_rekening']);
        });

        // The rekening the whole system has been using all along.
        $bank = DB::table('accounts')->where('kode', AccountCode::BANK)->first();

        if ($bank !== null) {
            $rekening = config('perusahaan.rekening', []);

            $id = DB::table('bank_accounts')->insertGetId([
                'nama' => 'Rekening utama',
                'bank' => (string) ($rekening['bank'] ?? ''),
                'nomor' => (string) ($rekening['nomor'] ?? ''),
                'atas_nama' => (string) ($rekening['atas_nama'] ?? ''),
                'account_id' => $bank->id,
                'is_default' => true,
                'aktif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Every past reconciliation was of this account, by definition.
            DB::table('bank_reconciliations')->whereNull('bank_account_id')
                ->update(['bank_account_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $t) {
            $t->dropUnique(['bank_account_id', 'tanggal_rekening']);
            $t->dropConstrainedForeignId('bank_account_id');
            $t->unique('tanggal_rekening');
        });
        Schema::table('supplier_payment_entries', fn (Blueprint $t) => $t->dropConstrainedForeignId('bank_account_id'));
        Schema::table('payment_entries', fn (Blueprint $t) => $t->dropConstrainedForeignId('bank_account_id'));
        Schema::dropIfExists('bank_accounts');
    }
};
