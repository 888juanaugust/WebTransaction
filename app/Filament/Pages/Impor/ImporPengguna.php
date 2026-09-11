<?php

declare(strict_types=1);

namespace App\Filament\Pages\Impor;

use App\Domain\Import\TemplateKind;
use App\Domain\Import\UserImporter;
use App\Filament\Navigation\SidebarGroups;

/**
 * Staff accounts from a spreadsheet. Owner only — the same hand that grants
 * every other permission, because PERAN, CABANG and GUDANG are permissions.
 */
class ImporPengguna extends ImporCsvPage
{
    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENGATURAN;

    protected static ?string $navigationLabel = 'Impor pengguna';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'impor-pengguna';

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canManageStaff() ?? false;
    }

    public function getTitle(): string
    {
        return 'Impor pengguna';
    }

    protected function kind(): TemplateKind
    {
        return TemplateKind::Pengguna;
    }

    protected function importer(): object
    {
        return app(UserImporter::class);
    }

    public function penjelasan(): string
    {
        return 'Satu baris satu akun staf. Akun dibuat lewat jalur yang sama dengan layar Staf, '
            .'jadi aturannya sama: satu gudang satu akun Gudang, Marketing dan Pemilik tanpa cabang. '
            .'Email yang sudah terdaftar ditahan — akun yang ada diubah dari layar Staf, bukan dari berkas.';
    }

    protected function konfirmasi(): array
    {
        return ['Buat akun staf', 'Baris yang tertahan dilewati. Setiap akun tercatat di log audit.'];
    }

    protected function kalimatHasil(array $hasil): string
    {
        return sprintf('%d akun staf dibuat, %d tertahan.', $hasil['baru'], $hasil['tertahan']);
    }

    protected function direktori(): string
    {
        return 'impor-pengguna';
    }
}
