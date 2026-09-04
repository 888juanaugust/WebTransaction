<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Import\CompanyImporter;
use App\Domain\Import\CompanyImportRow;
use App\Domain\Import\CsvTemplate;
use App\Domain\Import\TemplateKind;
use App\Filament\Pages\ImporPelanggan;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PriceTier;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Customers from a spreadsheet.
 *
 * The testers' complaint was that every customer had to be typed in one at a
 * time. The risk in fixing that is a bulk tool that writes faster than anyone
 * can check it, so most of what follows is about the reading half: a bad row
 * is held back with a reason, the rest of the file still lands, and nothing
 * is written until somebody has seen what would be.
 */
class CompanyImportTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marketing = User::factory()->role(Role::Marketing)->create();
        $this->finance = User::factory()->role(Role::Finance)->create();
    }

    // ------------------------------------------------------------ the format

    public function test_the_template_is_generated_from_the_format_the_parser_reads(): void
    {
        /*
         * The whole point of shipping a template: prose drifts from the
         * parser, a generated file cannot. Its own header must import.
         */
        $csv = app(CsvTemplate::class)->toCsv(TemplateKind::Pelanggan);

        $rows = app(CompanyImporter::class)->preview($csv, $this->finance);

        $this->assertCount(2, $rows, 'Both example rows read cleanly.');
        $this->assertSame([], $rows[0]->alasan);
        $this->assertSame('PLG-001', $rows[0]->kode);
        $this->assertSame(CompanyImportRow::BARU, $rows[0]->status);
    }

    public function test_a_file_without_the_required_headings_says_which_one_is_missing(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/JENIS_USAHA/');

        app(CompanyImporter::class)->preview("KODE,NAMA\nPLG-9,Toko\n", $this->finance);
    }

    public function test_a_semicolon_file_from_indonesian_excel_reads_the_same(): void
    {
        // Excel in this locale saves with semicolons. Refusing that reads as
        // "the importer is broken", not "your Excel disagrees with mine".
        $csv = "KODE;NAMA;JENIS_USAHA\nPLG-7;Bengkel Titik Koma;bengkel\n";

        $rows = app(CompanyImporter::class)->preview($csv, $this->finance);

        $this->assertSame('Bengkel Titik Koma', $rows[0]->nama);
        $this->assertFalse($rows[0]->tertahan());
    }

    // ------------------------------------------------------------- the rules

    public function test_a_bad_row_is_held_back_and_the_rest_of_the_file_still_lands(): void
    {
        /*
         * The behaviour that decides whether this tool is usable. Eighty good
         * rows must not wait on six bad ones — otherwise the fix for a typo
         * is re-uploading the whole file and hoping.
         */
        $csv = implode("\n", [
            'KODE,NAMA,JENIS_USAHA',
            'PLG-001,Bengkel Satu,bengkel',
            ',Tanpa Kode,bengkel',
            'PLG-003,,bengkel',
            'PLG-004,Jenis Salah,warung',
            'PLG-005,Bengkel Lima,distributor',
        ])."\n";

        $hasil = app(CompanyImporter::class)->import($csv, $this->finance);

        $this->assertSame(2, $hasil['baru']);
        $this->assertSame(3, $hasil['tertahan']);
        $this->assertSame(
            ['PLG-001', 'PLG-005'],
            Company::query()->orderBy('kode')->pluck('kode')->all(),
        );
    }

    public function test_each_held_row_carries_the_reason_it_was_held(): void
    {
        $csv = "KODE,NAMA,JENIS_USAHA\nPLG-004,Jenis Salah,warung\n";

        $row = app(CompanyImporter::class)->preview($csv, $this->finance)[0];

        $this->assertTrue($row->tertahan());
        $this->assertStringContainsString("JENIS_USAHA 'warung' tidak dikenal", $row->alasan[0]);
        $this->assertSame(2, $row->baris, 'Numbered as the person sees it in Excel.');
    }

    public function test_the_same_kode_twice_in_one_file_is_a_blocker_not_a_race(): void
    {
        // Otherwise the second row silently overwrites the first and the
        // person believes they imported two customers.
        $csv = implode("\n", [
            'KODE,NAMA,JENIS_USAHA',
            'PLG-001,Bengkel Satu,bengkel',
            'PLG-001,Bengkel Satu Lagi,bengkel',
        ])."\n";

        $rows = app(CompanyImporter::class)->preview($csv, $this->finance);

        $this->assertFalse($rows[0]->tertahan());
        $this->assertTrue($rows[1]->tertahan());
        $this->assertStringContainsString('muncul dua kali', $rows[1]->alasan[0]);
    }

    public function test_a_known_kode_updates_the_customer_rather_than_duplicating_it(): void
    {
        /*
         * The routine file is last month's list with three rows added and two
         * phone numbers corrected. Creating a second row for an existing code
         * would split that customer's history in half.
         */
        $lama = Company::factory()->create([
            'kode' => 'PLG-001', 'nama' => 'Bengkel Jaya', 'telepon' => '0811111',
            'status' => Company::STATUS_ACTIVE,
        ]);

        $csv = "KODE,NAMA,JENIS_USAHA,TELEPON,STATUS\nPLG-001,Bengkel Jaya Motor,bengkel,0819999,aktif\n";

        $hasil = app(CompanyImporter::class)->import($csv, $this->finance);

        $this->assertSame(0, $hasil['baru']);
        $this->assertSame(1, $hasil['diperbarui']);
        $this->assertSame(1, Company::query()->count());

        $lama->refresh();
        $this->assertSame('Bengkel Jaya Motor', $lama->nama);
        $this->assertSame('0819999', $lama->telepon);
    }

    public function test_a_blank_status_arrives_awaiting_approval_and_says_so(): void
    {
        // A customer that starts active because a column was left blank is a
        // customer nobody approved.
        $csv = "KODE,NAMA,JENIS_USAHA\nPLG-001,Bengkel Baru,bengkel\n";

        $row = app(CompanyImporter::class)->preview($csv, $this->finance)[0];
        app(CompanyImporter::class)->import($csv, $this->finance);

        $this->assertSame(Company::STATUS_PENDING, Company::query()->first()->status);
        $this->assertStringContainsString('menunggu persetujuan', implode(' ', $row->catatan));
    }

    public function test_an_unknown_tier_is_refused_rather_than_guessed(): void
    {
        PriceTier::factory()->create(['nama' => 'Bengkel']);

        $csv = "KODE,NAMA,JENIS_USAHA,TIER\nPLG-1,Toko A,bengkel,Grosir\n";

        $row = app(CompanyImporter::class)->preview($csv, $this->finance)[0];

        $this->assertTrue($row->tertahan());
        $this->assertStringContainsString("TIER 'Grosir' tidak ada", $row->alasan[0]);
    }

    public function test_a_named_tier_resolves_to_its_id(): void
    {
        $tier = PriceTier::factory()->create(['nama' => 'Bengkel']);

        $csv = "KODE,NAMA,JENIS_USAHA,TIER\nPLG-1,Toko A,bengkel,Bengkel\n";
        app(CompanyImporter::class)->import($csv, $this->finance);

        $this->assertSame($tier->id, Company::query()->first()->price_tier_id);
    }

    public function test_rupiah_is_read_however_it_was_typed(): void
    {
        $csv = "KODE,NAMA,JENIS_USAHA,LIMIT_KREDIT,TEMPO_HARI\nPLG-1,Toko A,bengkel,\"50.000.000\",30\n";

        app(CompanyImporter::class)->import($csv, $this->finance);

        $company = Company::query()->first();
        $this->assertSame(50_000_000, $company->credit_limit_rupiah);
        $this->assertSame(30, $company->payment_terms_days);
    }

    public function test_a_limit_that_is_not_a_number_is_a_blocker(): void
    {
        $csv = "KODE,NAMA,JENIS_USAHA,LIMIT_KREDIT\nPLG-1,Toko A,bengkel,lima juta\n";

        $row = app(CompanyImporter::class)->preview($csv, $this->finance)[0];

        $this->assertTrue($row->tertahan());
        $this->assertStringContainsString('LIMIT_KREDIT bukan angka', $row->alasan[0]);
    }

    // ------------------------------------------------------ the money guard

    public function test_a_file_setting_credit_limits_is_refused_for_a_seat_that_may_not_set_them(): void
    {
        /*
         * The separation the customer form already enforces by hiding the
         * field. A CSV must not be the way around it — and the refusal is
         * outright rather than a silent drop, because somebody whose limits
         * vanished would never know to look.
         */
        $csv = "KODE,NAMA,JENIS_USAHA,LIMIT_KREDIT\nPLG-1,Toko A,bengkel,50000000\n";

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/LIMIT_KREDIT/');

        app(CompanyImporter::class)->preview($csv, $this->marketing);
    }

    public function test_the_same_file_with_the_column_left_blank_is_fine_for_that_seat(): void
    {
        $csv = "KODE,NAMA,JENIS_USAHA,LIMIT_KREDIT\nPLG-1,Toko A,bengkel,\n";

        $hasil = app(CompanyImporter::class)->import($csv, $this->marketing);

        $this->assertSame(1, $hasil['baru']);
        $this->assertSame(0, Company::query()->first()->credit_limit_rupiah);
    }

    public function test_a_limit_moved_by_import_is_audited_exactly_once(): void
    {
        /*
         * CompanyObserver already logs a credit-limit move on any update,
         * from wherever it came — a form, a widget, or this importer. The
         * importer must not log it a second time: two rows for one change
         * reads as two changes to whoever audits it later.
         */
        $company = Company::factory()->create([
            'kode' => 'PLG-1', 'credit_limit_rupiah' => 10_000_000,
        ]);

        $csv = "KODE,NAMA,JENIS_USAHA,LIMIT_KREDIT\nPLG-1,Toko A,bengkel,25000000\n";

        $this->actingAs($this->finance);
        app(CompanyImporter::class)->import($csv, $this->finance, sumber: 'daftar-agustus.csv');

        $rows = AuditLog::query()->where('action', 'credit_limit_override')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(10_000_000, $rows[0]->old_value['credit_limit_rupiah']);
        $this->assertSame(25_000_000, $rows[0]->new_value['credit_limit_rupiah']);
        $this->assertSame($this->finance->id, $rows[0]->actor_id);
        $this->assertSame(25_000_000, $company->refresh()->credit_limit_rupiah);
    }

    public function test_the_import_itself_is_logged_with_what_it_did(): void
    {
        $csv = "KODE,NAMA,JENIS_USAHA\nPLG-1,Toko A,bengkel\nPLG-2,,bengkel\n";

        app(CompanyImporter::class)->import($csv, $this->finance, sumber: 'agustus.csv');

        $row = AuditLog::query()->where('action', 'companies_imported')->firstOrFail();

        $this->assertSame(1, $row->new_value['baru']);
        $this->assertSame(1, $row->new_value['tertahan']);
        $this->assertSame('agustus.csv', $row->new_value['berkas']);
    }

    public function test_a_role_with_no_business_in_the_register_cannot_import(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/tidak berhak/');

        app(CompanyImporter::class)->preview(
            "KODE,NAMA,JENIS_USAHA\nPLG-1,Toko A,bengkel\n",
            User::factory()->role(Role::Warehouse)->create(),
        );
    }

    // ------------------------------------------------------------- the screen

    public function test_the_screen_previews_then_imports_across_two_requests(): void
    {
        /*
         * The bug this exists to catch: holding the preview rows in a public
         * Livewire property renders fine and then fails on the *next* request,
         * because Livewire cannot round-trip objects. The preview appeared,
         * and pressing Impor returned a 500. Two requests in one test is the
         * only way to see it.
         */
        Storage::fake('local');
        Storage::disk('local')->put('impor-pelanggan/uji.csv', implode("\n", [
            'KODE,NAMA,JENIS_USAHA',
            'PLG-201,Bengkel Uji,bengkel',
            'PLG-202,,bengkel',
        ])."\n");

        Livewire::actingAs($this->finance)
            ->test(ImporPelanggan::class)
            ->call('baca', 'impor-pelanggan/uji.csv')
            ->assertOk()
            ->assertSee('Bengkel Uji')
            ->assertSee('Tertahan')
            ->callAction('impor')
            ->assertOk()
            ->assertHasNoActionErrors();

        $this->assertSame(['PLG-201'], Company::query()->pluck('kode')->all());
    }

    public function test_the_screen_reports_a_file_it_cannot_read(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('impor-pelanggan/salah.csv', "KODE,NAMA\nPLG-1,Toko\n");

        Livewire::actingAs($this->finance)
            ->test(ImporPelanggan::class)
            ->call('baca', 'impor-pelanggan/salah.csv')
            ->assertOk()
            ->assertSee('JENIS_USAHA');
    }

    public function test_the_example_file_downloads_from_the_screen(): void
    {
        $page = Livewire::actingAs($this->finance)->test(ImporPelanggan::class)->instance();

        $response = $page->unduhContoh();

        $this->assertStringContainsString(
            'contoh-impor-pelanggan.csv',
            (string) $response->headers->get('content-disposition'),
        );

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString('KODE,NAMA,JENIS_USAHA', $csv);
    }

    public function test_previewing_writes_nothing(): void
    {
        // The half of this design that matters: reading is separate from
        // writing, so a preview that looks wrong costs nothing to abandon.
        app(CompanyImporter::class)->preview(
            "KODE,NAMA,JENIS_USAHA\nPLG-1,Toko A,bengkel\n",
            $this->finance,
        );

        $this->assertSame(0, Company::query()->count());
    }
}
