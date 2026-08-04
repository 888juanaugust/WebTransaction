<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProductsTableRendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_products_table_shows_list_prices_without_a_query_per_row(): void
    {
        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        for ($i = 0; $i < 15; $i++) {
            $kode = 'PT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            Product::factory()->create(['kode' => $kode]);
            PriceListItem::factory()->create([
                'version_id' => $version->id, 'kode' => $kode, 'harga' => 250_000,
            ]);
        }

        $this->actingAs(User::factory()->owner()->create());

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListProducts::class)
            ->assertOk()
            ->assertSee('PT-000')
            ->assertSee('Rp 250.000');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $pricing = array_filter(
            $log,
            fn (array $q) => (bool) preg_match('/\bprice_list_items\b/', $q['query']),
        );

        $this->assertLessThanOrEqual(
            2,
            count($pricing),
            'The products table is reading the price list once per row again.'
        );
    }
}
