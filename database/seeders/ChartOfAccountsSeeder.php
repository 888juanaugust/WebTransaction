<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\AccountCode;
use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The chart of accounts, from the one definition of it.
 *
 * Reference data rather than demo data: the posting rules name these codes, so
 * a database without them cannot post anything. It runs from DatabaseSeeder
 * alongside the warehouse and the price tiers, and it is safe to re-run —
 * existing accounts keep whatever an accountant has renamed them to, because
 * the seeder only fills in what is missing.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $byCode = [];

        foreach (AccountCode::chart() as $definition) {
            $account = Account::query()->firstOrCreate(
                ['kode' => $definition['kode']],
                [
                    'nama' => $definition['nama'],
                    'tipe' => $definition['tipe'],
                    'saldo_normal' => $definition['tipe']->normalBalance(),
                    'dapat_diposting' => $definition['dapat_diposting'],
                    'parent_id' => $definition['induk'] === null
                        ? null
                        : ($byCode[$definition['induk']] ?? null)?->id,
                    'aktif' => true,
                    'catatan' => $definition['catatan'],
                ],
            );

            $byCode[$definition['kode']] = $account;
        }
    }
}
