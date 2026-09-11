<?php

declare(strict_types=1);

namespace App\Filament\Pages\Impor;

use App\Domain\Import\SaldoAwalHutangImporter;
use App\Domain\Import\TemplateKind;
use App\Domain\Money;
use App\Filament\Navigation\SidebarGroups;

/**
 * What we already owed suppliers when this system took over. Finance and
 * the Owner, for the same reason as the receivable side.
 */
class ImporHutang extends ImporCsvPage
{
    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PEMBELIAN;

    protected static ?string $navigationLabel = 'Impor saldo awal hutang';

    protected static ?int $navigationSort = 63;

    protected static ?string $slug = 'impor-saldo-awal-hutang';

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canConfirmPayment() ?? false;
    }

    public function getTitle(): string
    {
        return 'Impor saldo awal hutang';
    }

    protected function kind(): TemplateKind
    {
        return TemplateKind::Hutang;
    }

    protected function importer(): object
    {
        return app(SaldoAwalHutangImporter::class);
    }

    public function penjelasan(): string
    {
        return 'Hutang yang dibawa dari pembukuan lama: satu baris satu tagihan pemasok yang masih terbuka, '
            .'dengan sisa yang masih kami hutang hari ini. Setiap baris jadi tagihan pemasok sungguhan yang '
            .'menua dan dibayar lewat Bayar pemasok seperti tagihan lain. Dibukukan lawan Saldo Awal Konversi, '
            .'bukan Persediaan — barangnya sudah di rak dan sudah dinilai saat stok dihitung masuk.';
    }

    protected function konfirmasi(): array
    {
        return ['Bukukan saldo awal hutang', 'Setiap baris jadi tagihan terbuka dan satu jurnal Saldo Awal Konversi / Utang Usaha.'];
    }

    protected function kalimatHasil(array $hasil): string
    {
        return sprintf(
            '%d tagihan saldo awal dibukukan (%s), %d tertahan.',
            $hasil['baru'],
            Money::format((int) ($hasil['total'] ?? 0)),
            $hasil['tertahan'],
        );
    }

    protected function direktori(): string
    {
        return 'impor-saldo-awal-hutang';
    }
}
