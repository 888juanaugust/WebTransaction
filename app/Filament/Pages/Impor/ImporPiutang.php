<?php

declare(strict_types=1);

namespace App\Filament\Pages\Impor;

use App\Domain\Import\SaldoAwalPiutangImporter;
use App\Domain\Import\TemplateKind;
use App\Domain\Money;
use App\Filament\Navigation\SidebarGroups;

/**
 * What customers already owed us when this system took over. Finance and
 * the Owner — the seats that confirm money, because this is money.
 */
class ImporPiutang extends ImporCsvPage
{
    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::KEUANGAN;

    protected static ?string $navigationLabel = 'Impor saldo awal piutang';

    protected static ?int $navigationSort = 47;

    protected static ?string $slug = 'impor-saldo-awal-piutang';

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canConfirmPayment() ?? false;
    }

    public function getTitle(): string
    {
        return 'Impor saldo awal piutang';
    }

    protected function kind(): TemplateKind
    {
        return TemplateKind::Piutang;
    }

    protected function importer(): object
    {
        return app(SaldoAwalPiutangImporter::class);
    }

    public function penjelasan(): string
    {
        return 'Piutang yang dibawa dari pembukuan lama: satu baris satu faktur yang masih terbuka, '
            .'dengan sisa yang masih terhutang hari ini. Setiap baris jadi faktur sungguhan — menua dari '
            .'tanggal aslinya, tampil di rekening pelanggan, mengurangi limit kredit, dan dilunasi lewat '
            .'Terima pembayaran seperti faktur lain. Dibukukan lawan Saldo Awal Konversi, bukan Penjualan, '
            .'dan tidak masuk komisi maupun ekspor faktur pajak.';
    }

    protected function konfirmasi(): array
    {
        return ['Bukukan saldo awal piutang', 'Setiap baris jadi faktur terbuka dan satu jurnal Piutang Usaha / Saldo Awal Konversi.'];
    }

    protected function kalimatHasil(array $hasil): string
    {
        return sprintf(
            '%d faktur saldo awal dibukukan (%s), %d tertahan.',
            $hasil['baru'],
            Money::format((int) ($hasil['total'] ?? 0)),
            $hasil['tertahan'],
        );
    }

    protected function direktori(): string
    {
        return 'impor-saldo-awal-piutang';
    }
}
