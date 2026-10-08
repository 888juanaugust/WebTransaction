<?php

use App\Domain\Pengaturan\Preferensi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The accounts realised exchange differences go to, for installations that
 * already have a chart of accounts (a new one gets them from its seeder):
 * 7300 Exchange Gains and 8300 Exchange Losses, when those numbers are free.
 * Otherwise the preferences stay empty and a foreign-currency settlement is
 * refused until they are set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('accounts')->exists()) {
            return; // not installed yet: the chart seeder adds them
        }
        foreach ([['7300', 'Exchange Gains', 'other_income', 'accounts.exchange_gain'], ['8300', 'Exchange Losses', 'other_expense', 'accounts.exchange_loss']] as [$no, $name, $type, $key]) {
            if (DB::table('accounts')->where('no', $no)->exists() || DB::table('preferences')->where('key', $key)->exists()) {
                continue;
            }
            $id = DB::table('accounts')->insertGetId(['no' => $no, 'name' => $name, 'account_type' => $type, 'is_system' => true, 'is_active' => true, 'used_all_user' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('preferences')->insert(['key' => $key, 'value' => json_encode($id), 'updated_at' => now()]);
        }
        app(Preferensi::class)->forget(); // the cached preferences predate them
    }

    public function down(): void
    {
        // The accounts may already carry postings; they stay.
    }
};
