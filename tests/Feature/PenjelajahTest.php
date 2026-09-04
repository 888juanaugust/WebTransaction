<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Explorer\ExplorerColumn;
use App\Domain\Explorer\ExplorerDataset;
use App\Filament\Pages\Laporan\Penjelajah;
use App\Models\Company;
use App\Models\Product;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The explorer, and the two ways a screen like it goes wrong.
 *
 * A free-form data browser is the obvious back door around every role rule in
 * the system — pick a different dataset from a dropdown and read what your
 * seat is kept away from everywhere else. So most of this is about the gates:
 * per dataset, per column, and on the download as much as on the screen.
 *
 * The rest is about saved views storing the *question*. A view that cached its
 * answer would go stale silently and still be believed.
 */
class PenjelajahTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- the gates

    #[DataProvider('datasetAccess')]
    public function test_who_may_point_the_explorer_at_what(Role $role, string $dataset, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create(), 'web');

        $this->assertSame($allowed, ExplorerDataset::from($dataset)->canAccess());
    }

    public static function datasetAccess(): array
    {
        return [
            // Credit data is the line the customer and invoice registers sit behind.
            'gudang tidak boleh faktur' => [Role::Warehouse, 'faktur', false],
            'gudang tidak boleh pelanggan' => [Role::Warehouse, 'pelanggan', false],
            'gudang boleh stok' => [Role::Warehouse, 'stok', true],
            'gudang boleh barang' => [Role::Warehouse, 'barang', true],
            'sales boleh faktur' => [Role::Sales, 'faktur', true],
            'sales boleh pelanggan' => [Role::Sales, 'pelanggan', true],
            'keuangan boleh faktur' => [Role::Finance, 'faktur', true],
            'pemilik boleh semua' => [Role::Owner, 'faktur', true],
        ];
    }

    public function test_a_dataset_the_reader_may_not_open_is_refused_even_when_typed_in(): void
    {
        /*
         * The gate has to be on the page, not only on the dropdown. Hiding an
         * option from a select is a display choice; this is the one that
         * matters.
         */
        $this->actingAs(User::factory()->role(Role::Warehouse)->create(), 'web');

        $page = Livewire::test(Penjelajah::class)->instance();
        $page->dataset = ExplorerDataset::Faktur->value;

        try {
            $page->kumpulan();
            $this->fail('A dataset this seat may not open must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_the_dropdown_offers_only_what_the_reader_may_open(): void
    {
        $this->actingAs(User::factory()->role(Role::Warehouse)->create(), 'web');

        $tersedia = array_map(fn (ExplorerDataset $d) => $d->value, ExplorerDataset::tersedia());

        $this->assertSame(['order', 'barang', 'stok'], $tersedia);
    }

    public function test_a_reader_without_prices_gets_no_price_columns_anywhere(): void
    {
        /*
         * Including the download. A column hidden on screen but present in the
         * CSV is worse than one that was never hidden — it looks handled.
         */
        $this->actingAs(User::factory()->role(Role::Warehouse)->create(), 'web');

        $labels = collect(ExplorerDataset::Barang->columns())
            ->filter(fn (ExplorerColumn $c) => $c->isVisible())
            ->map(fn (ExplorerColumn $c) => $c->label);

        $this->assertNotContains('Total', $labels);

        // And the same definitions are what the CSV is built from.
        $this->actingAs(User::factory()->role(Role::Finance)->create(), 'web');

        $keuangan = collect(ExplorerDataset::Faktur->columns())
            ->filter(fn (ExplorerColumn $c) => $c->isVisible())
            ->map(fn (ExplorerColumn $c) => $c->label);

        $this->assertContains('Total', $keuangan);
    }

    // --------------------------------------------------------- the arranging

    public function test_the_table_lists_rows_and_can_be_sorted_by_any_plain_column(): void
    {
        $this->actingAs(User::factory()->role(Role::Finance)->create(), 'web');

        Company::factory()->create(['kode' => 'PLG-002', 'nama' => 'Zeta Motor']);
        Company::factory()->create(['kode' => 'PLG-001', 'nama' => 'Alfa Motor']);

        Livewire::test(Penjelajah::class)
            ->set('dataset', ExplorerDataset::Pelanggan->value)
            ->assertOk()
            ->assertSee('Alfa Motor')
            ->assertSee('Zeta Motor')
            ->call('sortTable', 'nama')
            ->assertOk();
    }

    public function test_changing_dataset_clears_a_filter_that_would_mean_nothing(): void
    {
        // "status = lunas" carried onto the barang list would filter on a
        // column that is not there. Asserted by what the screen shows, since
        // that is what a stale filter would break.
        $this->actingAs(User::factory()->role(Role::Finance)->create(), 'web');

        Product::factory()->create(['kode' => 'YH-1', 'merk' => 'YUHOLI']);

        Livewire::test(Penjelajah::class)
            ->set('dataset', ExplorerDataset::Faktur->value)
            ->set('tableFilters', ['status' => ['value' => 'paid']])
            ->set('dataset', ExplorerDataset::Barang->value)
            ->assertOk()
            ->assertSee('YH-1');
    }

    // ----------------------------------------------------------- saved views

    public function test_a_saved_view_stores_the_question_not_the_answer(): void
    {
        $finance = User::factory()->role(Role::Finance)->create();
        $this->actingAs($finance, 'web');

        Livewire::test(Penjelajah::class)
            ->set('dataset', ExplorerDataset::Faktur->value)
            ->set('tableFilters', ['status' => ['value' => 'open']])
            ->set('tableSearch', 'INV-')
            ->callAction('simpan', ['nama' => 'Faktur terbuka']);

        $view = SavedView::query()->firstOrFail();

        $this->assertSame('Faktur terbuka', $view->nama);
        $this->assertSame('faktur', $view->dataset);
        $this->assertSame(['status' => ['value' => 'open']], $view->filters);
        $this->assertSame('INV-', $view->pencarian);
        $this->assertFalse($view->dibagikan);
    }

    public function test_saving_the_same_name_twice_updates_rather_than_duplicating(): void
    {
        $finance = User::factory()->role(Role::Finance)->create();
        $this->actingAs($finance, 'web');

        foreach (['open', 'paid'] as $status) {
            Livewire::test(Penjelajah::class)
                ->set('dataset', ExplorerDataset::Faktur->value)
                ->set('tableFilters', ['status' => ['value' => $status]])
                ->callAction('simpan', ['nama' => 'Punyaku']);
        }

        $this->assertSame(1, SavedView::query()->count());
        $this->assertSame(
            ['status' => ['value' => 'paid']],
            SavedView::query()->first()->filters,
        );
    }

    public function test_applying_a_saved_view_restores_the_arrangement(): void
    {
        $finance = User::factory()->role(Role::Finance)->create();
        $this->actingAs($finance, 'web');

        $view = SavedView::query()->create([
            'user_id' => $finance->id,
            'dataset' => 'faktur',
            'nama' => 'Lewat tempo',
            'filters' => ['status' => ['value' => 'open']],
            'urutan' => 'due_date:asc',
            'pencarian' => 'INV',
        ]);

        Livewire::test(Penjelajah::class)
            ->set('dataset', ExplorerDataset::Faktur->value)
            ->callAction('pakai', arguments: ['view' => $view->id])
            ->assertSet('tableSort', 'due_date:asc')
            ->assertSet('tableSearch', 'INV')
            // Both halves of the filter state, so the query and the controls
            // agree about what is being filtered.
            ->assertSet('tableFilters.status.value', 'open')
            ->assertSet('tableDeferredFilters.status.value', 'open');
    }

    public function test_a_colleagues_private_view_is_invisible_and_a_shared_one_is_not(): void
    {
        $mine = User::factory()->role(Role::Finance)->create();
        $theirs = User::factory()->role(Role::Finance)->create();

        SavedView::query()->create([
            'user_id' => $theirs->id, 'dataset' => 'faktur',
            'nama' => 'Rahasia', 'dibagikan' => false,
        ]);
        SavedView::query()->create([
            'user_id' => $theirs->id, 'dataset' => 'faktur',
            'nama' => 'Dibagi', 'dibagikan' => true,
        ]);

        $this->actingAs($mine, 'web');

        $names = SavedView::query()->readableBy($mine->id)->pluck('nama')->all();

        $this->assertSame(['Dibagi'], $names);
    }

    public function test_only_the_owner_of_a_view_may_delete_it_shared_or_not(): void
    {
        $mine = User::factory()->role(Role::Finance)->create();
        $theirs = User::factory()->role(Role::Finance)->create();

        $view = SavedView::query()->create([
            'user_id' => $theirs->id, 'dataset' => 'faktur',
            'nama' => 'Dibagi', 'dibagikan' => true,
        ]);

        $this->actingAs($mine, 'web');

        Livewire::test(Penjelajah::class)
            ->set('dataset', ExplorerDataset::Faktur->value)
            ->callAction('hapusTampilan', arguments: ['view' => $view->id]);

        $this->assertSame(1, SavedView::query()->count(), 'Somebody else\'s view survives.');
    }

    public function test_a_view_saved_against_another_dataset_is_not_applied(): void
    {
        // Filters from the barang list would be nonsense against faktur, and
        // an id typed into the request must not smuggle them across.
        $finance = User::factory()->role(Role::Finance)->create();
        $this->actingAs($finance, 'web');

        $view = SavedView::query()->create([
            'user_id' => $finance->id, 'dataset' => 'barang',
            'nama' => 'Merk YUHOLI', 'filters' => ['merk' => ['value' => 'YUHOLI']],
        ]);

        Livewire::test(Penjelajah::class)
            ->set('dataset', ExplorerDataset::Faktur->value)
            ->callAction('pakai', arguments: ['view' => $view->id])
            ->assertSet('tableFilters.merk', null);
    }

    // ------------------------------------------------------------------ CSV

    public function test_the_download_carries_the_filtered_rows_and_the_visible_columns(): void
    {
        $this->actingAs(User::factory()->role(Role::Finance)->create(), 'web');

        Product::factory()->create(['kode' => 'YH-1', 'merk' => 'YUHOLI', 'description' => 'Master rem']);
        Product::factory()->create(['kode' => 'OS-2', 'merk' => 'OSBORN', 'description' => 'Bearing roda']);

        $csv = $this->unduh(ExplorerDataset::Barang, ['merk' => ['value' => 'YUHOLI']]);

        $this->assertStringContainsString('YH-1', $csv);
        $this->assertStringNotContainsString('OS-2', $csv, 'The filter applies to the download too.');
        $this->assertStringContainsString('Kode;Merk', $csv, 'Semicolons, for Excel in this locale.');
    }

    public function test_the_download_omits_the_columns_the_reader_may_not_see(): void
    {
        $this->actingAs(User::factory()->role(Role::Warehouse)->create(), 'web');

        Product::factory()->create(['kode' => 'YH-1', 'merk' => 'YUHOLI']);

        $csv = $this->unduh(ExplorerDataset::Barang, []);

        $this->assertStringContainsString('YH-1', $csv);
        $this->assertStringNotContainsString('Total', $csv);
    }

    /** @param array<string, mixed> $filters */
    private function unduh(ExplorerDataset $set, array $filters): string
    {
        $component = Livewire::test(Penjelajah::class)
            ->set('dataset', $set->value)
            ->set('tableFilters', $filters)
            ->set('tableDeferredFilters', $filters);

        // The response streams; run it and capture what it actually wrote,
        // rather than trusting the headers.
        ob_start();
        $component->instance()->unduh()->sendContent();

        return (string) ob_get_clean();
    }
}
