<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeded rows must survive their own edit forms.
 *
 * The demo seeder once stored the *label* ('Bengkel') where the form saves
 * the *key* ('bengkel'). Every table rendered it fine — the column prints
 * whatever it holds — and the edit form could not: the required select had
 * no matching option, rendered empty, and refused every save of a seeded
 * customer. Data only a form can reveal as wrong needs a test that checks it
 * the way the form does.
 */
class DemoSeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_companies_hold_option_keys_not_labels(): void
    {
        // DemoSeeder builds on the staff accounts DatabaseSeeder creates,
        // the same order `migrate --seed` then `db:seed --class=DemoSeeder`
        // runs in for real.
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $sah = ['bengkel', 'toko_sparepart', 'distributor'];

        $salah = Company::query()
            ->whereNotIn('jenis_usaha', $sah)
            ->pluck('jenis_usaha', 'kode')
            ->all();

        $this->assertSame([], $salah, 'Companies seeded with a label where the form expects a key: '
            .json_encode($salah));
    }
}
