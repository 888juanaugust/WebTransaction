<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cart\CartService;
use App\Domain\Uom\Unit;
use App\Filament\Portal\Pages\Keranjang;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Product;
use App\Support\Legal\DataInventory;
use App\Support\Legal\Terms;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kebijakan Privasi and Syarat Penjualan.
 *
 * The interesting test here is not that the pages render — it is
 * test_every_column_holding_personal_data_is_declared_in_the_notice, which
 * reads the live schema and fails the build if a column appears that the
 * privacy notice does not account for.
 *
 * That is the whole design. A privacy policy is a factual claim about a
 * database, and databases change every week while policies are rewritten once
 * and then forgotten. UU PDP Pasal 21 requires the notice to state the types of
 * data processed; the only way that stays true is if adding an unclassified
 * column breaks something.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    // --- the notice cannot drift from the schema ----------------------------

    /**
     * @return list<array{0: string}>
     */
    public static function inventoriedTables(): array
    {
        return array_map(fn (string $table) => [$table], array_keys(DataInventory::tables()));
    }

    #[DataProvider('inventoriedTables')]
    public function test_every_column_holding_personal_data_is_declared_in_the_notice(string $table): void
    {
        $actual = Schema::getColumnListing($table);

        $this->assertNotEmpty($actual, "table {$table} does not exist");

        $accounted = DataInventory::accountedColumns($table);

        $unclassified = array_values(array_diff($actual, $accounted));

        $this->assertSame([], $unclassified, sprintf(
            "New column(s) on `%s` that the privacy notice does not account for: %s.\n".
            'Add each one to App\\Support\\Legal\\DataInventory under `personal` if it holds or '.
            'identifies personal data, or under `bukan` if it does not. Do not skip this: the '.
            'notice is a factual statement about what is in the database.',
            $table,
            implode(', ', $unclassified),
        ));
    }

    #[DataProvider('inventoriedTables')]
    public function test_the_inventory_does_not_describe_columns_that_no_longer_exist(string $table): void
    {
        $actual = Schema::getColumnListing($table);
        $entry = DataInventory::tables()[$table];

        $phantom = array_values(array_diff([...$entry['personal'], ...$entry['bukan']], $actual));

        $this->assertSame([], $phantom, sprintf(
            'The privacy notice claims `%s` holds column(s) that were dropped: %s.',
            $table,
            implode(', ', $phantom),
        ));
    }

    /**
     * Every table in the database must be classified, one way or the other.
     *
     * This is the half that was missing. The column check above only walks
     * tables the inventory already knows about, so a brand-new table holding
     * personal data was invisible to it — which is exactly what happened when
     * suppliers and goods receipts arrived. A guard that only checks what it
     * was already told about is not a guard.
     */
    public function test_every_table_in_the_database_is_classified(): void
    {
        $tables = collect(DB::select(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'
        ))->pluck('tablename');

        $classified = [
            ...array_keys(DataInventory::tables()),
            ...DataInventory::tablesWithoutPersonalData(),
        ];

        $unclassified = $tables->reject(fn (string $t) => in_array($t, $classified, true))->values()->all();

        $this->assertSame([], $unclassified, sprintf(
            "Table(s) the privacy notice has never been told about: %s.\n".
            'Add each to App\\Support\\Legal\\DataInventory — to tables() if it holds anything '.
            'about an identifiable person, or to tablesWithoutPersonalData() if it does not.',
            implode(', ', $unclassified),
        ));
    }

    /** And nothing may be claimed as unclassifiable that no longer exists. */
    public function test_the_no_personal_data_list_names_only_real_tables(): void
    {
        foreach (DataInventory::tablesWithoutPersonalData() as $table) {
            $this->assertNotEmpty(
                Schema::getColumnListing($table),
                "the inventory lists `{$table}` as holding no personal data, but it does not exist"
            );
        }
    }

    /**
     * Every category the inventory assigns a table to must be one the notice
     * actually renders, or the data is classified into a heading no reader
     * ever sees.
     */
    public function test_every_table_belongs_to_a_category_the_notice_publishes(): void
    {
        $published = array_column(DataInventory::categories(), 'kunci');

        foreach (DataInventory::tables() as $table => $entry) {
            $this->assertContains(
                $entry['kategori'],
                $published,
                "table {$table} is filed under a category the notice never shows"
            );
        }
    }

    public function test_every_published_category_has_at_least_one_table_behind_it(): void
    {
        $used = array_keys(DataInventory::tablesByCategory());

        foreach (DataInventory::categories() as $category) {
            $this->assertContains(
                $category['kunci'],
                $used,
                "the notice describes '{$category['kunci']}' but no table holds that data"
            );
        }
    }

    /**
     * A retention period without a stated purpose and legal basis is not a
     * disclosure. UU PDP Pasal 21 asks for all three.
     */
    public function test_every_category_states_a_purpose_a_legal_basis_and_a_retention_period(): void
    {
        foreach (DataInventory::categories() as $category) {
            foreach (['judul', 'isi', 'tujuan', 'dasar', 'retensi'] as $field) {
                $this->assertNotEmpty(
                    trim($category[$field] ?? ''),
                    "category {$category['kunci']} is missing `{$field}`"
                );
            }
        }
    }

    // --- the pages ----------------------------------------------------------

    public function test_the_privacy_notice_is_public_and_lists_every_data_category(): void
    {
        $response = $this->get(route('publik.privasi'))->assertOk();

        $response->assertSee('Kebijakan Privasi')
            ->assertSee('Undang-Undang Nomor 27 Tahun 2022')
            ->assertSee(config('perusahaan.nama'));

        // The rendered page must show every category, not a sample of them.
        foreach (DataInventory::categories() as $category) {
            $response->assertSee($category['judul']);
        }
    }

    /**
     * The rights in UU PDP Pasal 5–15. "You have certain rights" is not a
     * disclosure — a reader has to be able to tell what to ask for.
     */
    public function test_the_privacy_notice_enumerates_the_rights_of_a_data_subject(): void
    {
        $this->get(route('publik.privasi'))
            ->assertOk()
            ->assertSee('Melihat dan mendapat salinan')
            ->assertSee('Memperbaiki')
            ->assertSee('Menghapus')
            ->assertSee('Menarik persetujuan')
            ->assertSee('Menolak keputusan otomatis')
            ->assertSee('Memindahkan data')
            ->assertSee('Menggugat dan menuntut ganti rugi');
    }

    /** A right with no address to send it to is decorative. */
    public function test_the_privacy_notice_gives_a_working_channel_for_requests(): void
    {
        $this->get(route('publik.privasi'))
            ->assertOk()
            ->assertSee('mailto:'.config('legal.privasi.email'), escape: false)
            // Pasal 37 and Pasal 46 both run to 3 × 24 hours.
            ->assertSee('72 jam');
    }

    /**
     * The cookie section is a factual claim about HTTP responses, and it was
     * wrong the first time it was written — the public pages do set a session
     * cookie. Assert against the configured name so the page cannot name a
     * cookie the app does not send.
     */
    public function test_the_privacy_notice_names_the_cookies_actually_set(): void
    {
        $response = $this->get(route('publik.beranda'))->assertOk();

        $names = array_map(
            fn ($cookie) => $cookie->getName(),
            $response->headers->getCookies(),
        );

        $this->assertNotEmpty($names, 'the public site does set cookies');

        $notice = $this->get(route('publik.privasi'))->assertOk()->getContent();

        foreach ($names as $name) {
            $this->assertStringContainsString(
                $name,
                $notice,
                "cookie `{$name}` is set on the public site but the notice does not name it"
            );
        }
    }

    public function test_the_terms_of_sale_are_public_and_cover_what_they_must(): void
    {
        $this->get(route('publik.syarat'))
            ->assertOk()
            ->assertSee('Syarat Penjualan')
            // The four subjects CLAUDE.md requires the terms to cover.
            ->assertSee('Limit kredit dan termin pembayaran')
            ->assertSee('Keterlambatan')
            ->assertSee('Klaim, pengembalian, dan garansi')
            ->assertSee('Pengiriman dan penyerahan risiko');
    }

    /**
     * The terms promise a reservation window. It has to be the window the
     * release job actually enforces, or the document is describing software
     * that does not exist.
     */
    public function test_the_reservation_window_in_the_terms_matches_the_one_the_job_enforces(): void
    {
        $configured = intdiv((int) config('penjualan.reservation_ttl_minutes'), 60);

        $this->assertSame($configured, config('legal.syarat.kadaluarsa_reservasi_jam'));

        $this->get(route('publik.syarat'))
            ->assertOk()
            ->assertSee("{$configured} jam");
    }

    /**
     * The terms say a buyer accepts them by placing an order. That clause is
     * only worth something if the buyer can reach them from the screen where
     * they place it, so the notice must carry a live link — not the words alone.
     *
     * This asserts the copy itself. Whether it is mounted on the checkout
     * dialogue is not asserted here: Filament renders modal bodies client-side,
     * so a Livewire test of the cart page cannot see them, and a test that
     * quietly passes on markup it never inspected is worse than no test. That
     * wiring was checked in a browser instead.
     */
    public function test_the_order_acceptance_notice_links_to_the_terms(): void
    {
        $notice = Terms::persetujuanPesanan()->toHtml();

        $this->assertStringContainsString('Syarat Penjualan', $notice);
        $this->assertStringContainsString('href="'.route('publik.syarat').'"', $notice);
        $this->assertStringContainsString('menyetujui', $notice);
    }

    /** The checkout dialogue is the screen that notice has to appear on. */
    public function test_the_cart_offers_checkout_once_there_is_something_in_it(): void
    {
        $buyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create(['status' => Company::STATUS_ACTIVE])->id,
        ]);

        $this->actingAs($buyer, 'customer');
        Filament::setCurrentPanel('portal');

        Livewire::test(Keranjang::class)->assertActionHidden('checkout');

        Product::factory()->create(['kode' => 'YH-SYARAT-1', 'qty_per_ctn' => 12]);
        app(CartService::class)->add($buyer, 'YH-SYARAT-1', Unit::Pcs, 2);

        Livewire::test(Keranjang::class)->assertActionVisible('checkout');
    }

    public function test_the_two_documents_point_at_each_other(): void
    {
        $this->get(route('publik.privasi'))->assertOk()->assertSee(route('publik.syarat'), escape: false);
        $this->get(route('publik.syarat'))->assertOk()->assertSee(route('publik.privasi'), escape: false);
    }

    /**
     * Reachable from every page. A policy nobody can find is a policy that does
     * not satisfy the obligation to inform.
     */
    #[DataProvider('publicPages')]
    public function test_both_documents_are_linked_from_the_footer_of_every_public_page(string $route): void
    {
        $this->get(route($route))
            ->assertOk()
            ->assertSee(route('publik.privasi'), escape: false)
            ->assertSee(route('publik.syarat'), escape: false);
    }

    /** @return list<array{0: string}> */
    public static function publicPages(): array
    {
        return [
            ['publik.beranda'],
            ['publik.tentang'],
            ['publik.mitra'],
            ['publik.rencana'],
            ['publik.kontak'],
            ['publik.privasi'],
            ['publik.syarat'],
        ];
    }
}
