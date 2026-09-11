<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Import\CsvTemplate;
use App\Domain\Import\TemplateKind;
use App\Domain\Import\UserImporter;
use App\Models\AuditLog;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff accounts from a spreadsheet.
 *
 * Create-only, through `StaffRegistrar`, so every rule the Staf form
 * enforces holds here too — and the interesting tests are the refusals:
 * an email that already exists, a Gudang row with no gudang, a role nobody
 * has heard of. A CSV that could quietly re-role forty people is not a
 * feature.
 */
class UserImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'NAMA,EMAIL,PERAN,CABANG,GUDANG,KATA_SANDI';

    private User $owner;

    private Region $cabang;

    private Warehouse $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->cabang = $this->currentRegion();
        $this->gudang = Warehouse::factory()->create(['kode' => 'GD-UJI', 'region_id' => $this->cabang->id]);
    }

    private function csv(array $rows): string
    {
        return self::HEADER."\n".implode("\n", $rows)."\n";
    }

    private function importer(): UserImporter
    {
        return app(UserImporter::class);
    }

    public function test_one_file_creates_the_whole_team_through_the_registrar(): void
    {
        $kode = $this->cabang->kode;

        $hasil = $this->importer()->import($this->csv([
            "Budi Santoso,budi@uji.test,Sales,{$kode},,",
            'Sari Dewi,sari@uji.test,Marketing,,,',
            'Agus Pratama,agus@uji.test,Gudang,,GD-UJI,',
            "Rina Keuangan,rina@uji.test,Keuangan,{$kode},,RahasiaSekali2026",
        ]), $this->owner, sumber: 'staf.csv');

        $this->assertSame(['baru' => 4, 'tertahan' => 0], $hasil);

        $budi = User::query()->where('email', 'budi@uji.test')->sole();
        $this->assertSame(Role::Sales, $budi->role());
        $this->assertSame($this->cabang->id, $budi->region_id);

        // Marketing is global: no region, whatever the file said.
        $sari = User::query()->where('email', 'sari@uji.test')->sole();
        $this->assertSame(Role::Marketing, $sari->role());
        $this->assertNull($sari->region_id);

        // A packer is bound to the gudang, and the region follows it.
        $agus = User::query()->where('email', 'agus@uji.test')->sole();
        $this->assertSame(Role::Storage, $agus->role());
        $this->assertSame($this->gudang->id, $agus->warehouse_id);
        $this->assertSame($this->cabang->id, $agus->region_id);

        // A password given in the file is the password.
        $rina = User::query()->where('email', 'rina@uji.test')->sole();
        $this->assertTrue(Hash::check('RahasiaSekali2026', $rina->password));

        // The registrar audited each person, and the file got its own row.
        $this->assertSame(4, AuditLog::query()->where('action', 'staff_created')->count());
        $ringkasan = AuditLog::query()->where('action', 'users_imported')->sole();
        $this->assertSame(4, $ringkasan->new_value['baru']);
        $this->assertSame('staf.csv', $ringkasan->new_value['berkas']);
    }

    public function test_a_blank_password_is_generated_and_the_row_says_so(): void
    {
        $rows = $this->importer()->preview($this->csv([
            "Budi Santoso,budi@uji.test,Sales,{$this->cabang->kode},,",
        ]), $this->owner);

        $this->assertFalse($rows[0]->tertahan());
        $this->assertStringContainsString('Kata sandi acak', implode(' ', $rows[0]->catatan));
        $this->assertTrue($rows[0]->nilai['sandi_dibuat']);
        $this->assertGreaterThanOrEqual(20, strlen($rows[0]->nilai['password']));
    }

    public function test_an_existing_email_is_held_not_updated(): void
    {
        User::factory()->create(['email' => 'budi@uji.test', 'role' => Role::Finance->value]);

        $rows = $this->importer()->preview($this->csv([
            "Budi Santoso,BUDI@uji.test,Sales,{$this->cabang->kode},,",
        ]), $this->owner);

        $this->assertTrue($rows[0]->tertahan());
        $this->assertStringContainsString('sudah terdaftar', implode(' ', $rows[0]->alasan));

        // And the existing account is exactly as it was: still Finance.
        $this->importer()->import($this->csv(["Budi Santoso,budi@uji.test,Sales,{$this->cabang->kode},,"]), $this->owner);
        $this->assertSame(Role::Finance, User::query()->where('email', 'budi@uji.test')->sole()->role());
    }

    public function test_the_reasons_a_row_is_held_are_specific_enough_to_fix(): void
    {
        $rows = $this->importer()->preview($this->csv([
            ',kosong@uji.test,Sales,'.$this->cabang->kode.',,',
            'Tanpa Email,,Sales,'.$this->cabang->kode.',,',
            'Peran Aneh,aneh@uji.test,Direktur,'.$this->cabang->kode.',,',
            'Sales Tanpa Cabang,tanpa@uji.test,Sales,,,',
            'Cabang Salah,salah@uji.test,Sales,XYZ,,',
            'Gudang Tanpa Gudang,packer@uji.test,Gudang,,,',
            'Sandi Pendek,pendek@uji.test,Sales,'.$this->cabang->kode.',,pendek',
            'Dua Kali,dua@uji.test,Sales,'.$this->cabang->kode.',,',
            'Dua Kali Lagi,dua@uji.test,Sales,'.$this->cabang->kode.',,',
        ]), $this->owner);

        $alasan = array_map(fn ($r) => implode(' | ', $r->alasan), $rows);

        $this->assertStringContainsString('NAMA kosong', $alasan[0]);
        $this->assertStringContainsString('EMAIL kosong', $alasan[1]);
        $this->assertStringContainsString("PERAN 'Direktur' tidak dikenal", $alasan[2]);
        $this->assertStringContainsString('Pemilik', $alasan[2], 'the refusal lists the roles that exist');
        $this->assertStringContainsString('wajib punya CABANG', $alasan[3]);
        $this->assertStringContainsString("CABANG 'XYZ' tidak dikenal", $alasan[4]);
        $this->assertStringContainsString('wajib punya GUDANG', $alasan[5]);
        $this->assertStringContainsString('kurang dari 12 karakter', $alasan[6]);
        $this->assertSame('', $alasan[7]);
        $this->assertStringContainsString('muncul dua kali', $alasan[8]);
    }

    public function test_a_column_that_does_not_apply_to_the_role_is_ignored_with_a_note(): void
    {
        $rows = $this->importer()->preview($this->csv([
            "Sari Dewi,sari@uji.test,Marketing,{$this->cabang->kode},,",
            'Agus Pratama,agus@uji.test,Gudang,'.$this->cabang->kode.',GD-UJI,',
        ]), $this->owner);

        $this->assertFalse($rows[0]->tertahan());
        $this->assertStringContainsString('CABANG diabaikan', implode(' ', $rows[0]->catatan));
        $this->assertFalse($rows[1]->tertahan());
        $this->assertStringContainsString('mengikuti cabang gudangnya', implode(' ', $rows[1]->catatan));
    }

    public function test_the_preview_writes_nothing(): void
    {
        $this->importer()->preview($this->csv(["Budi,budi@uji.test,Sales,{$this->cabang->kode},,"]), $this->owner);

        $this->assertFalse(User::query()->where('email', 'budi@uji.test')->exists());
    }

    public function test_only_the_owner_may_import_staff(): void
    {
        foreach ([Role::Sales, Role::Marketing, Role::Warehouse, Role::Storage, Role::Finance] as $role) {
            $actor = User::factory()->create(['role' => $role->value, 'is_active' => true]);

            try {
                $this->importer()->preview($this->csv(["Budi,budi@uji.test,Sales,{$this->cabang->kode},,"]), $actor);
                $this->fail("{$role->value} should not be able to import staff");
            } catch (DomainException $e) {
                $this->assertStringContainsString('Pemilik', $e->getMessage());
            }
        }
    }

    public function test_the_example_file_is_one_the_importer_accepts(): void
    {
        // The template names SBY and GD-SBY; give it something to point at.
        Region::query()->where('id', '!=', $this->cabang->id)->delete();
        $this->cabang->update(['kode' => 'SBY']);
        $this->gudang->update(['kode' => 'GD-SBY']);

        $rows = $this->importer()->preview(app(CsvTemplate::class)->toCsv(TemplateKind::Pengguna), $this->owner);

        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertFalse($row->tertahan(), implode(' | ', $row->alasan));
        }
    }
}
