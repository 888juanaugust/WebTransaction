<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

/**
 * What WebTransaction does that ACCURATE does not, each behind a switch.
 *
 * The owner decided (2026-10-03, CLAUDE.md "ACCURATE parity") to match
 * ACCURATE first and modify later, and to keep these rather than strip them —
 * as switches, so the choice is theirs in the modify phase. Every switch is
 * **on by default**: on is exactly today's behaviour, which is what keeps the
 * existing suite and the existing business unchanged until someone decides.
 *
 * A switch is declared here before the code that honours it exists, so the
 * whole list is in one place (docs/accurate/PARITY.md mirrors it). Until its
 * phase lands, `diterapkan()` is false: the switch is not shown, and
 * `aktif()` answers true whatever is stored — a switch that appears to work
 * and does nothing is worse than no switch.
 */
enum Fitur: string
{
    case PeringatanPiutang = 'peringatan_piutang';
    case BekuKredit = 'beku_kredit';
    case Komisi = 'komisi';
    case KunjunganToko = 'kunjungan_toko';
    case EksporCoretax = 'ekspor_coretax';

    case PortalPembeli = 'portal_pembeli';
    case SitusPublik = 'situs_publik';
    case PersetujuanMarketingWajib = 'persetujuan_marketing_wajib';
    case PecahGudang = 'pecah_gudang';
    case ReservasiStok = 'reservasi_stok';
    case TagihSebelumKirim = 'tagih_sebelum_kirim';
    case PipelineImporHarga = 'pipeline_impor_harga';
    case HargaHanyaDariResolver = 'harga_hanya_dari_resolver';

    /** Whether the business currently has this behaviour switched on. */
    public function aktif(): bool
    {
        return app(Preferensi::class)->aktif($this);
    }

    /** The programme phase whose code honours the switch; null when it already does. */
    public function berlakuMulaiFase(): ?int
    {
        return match ($this) {
            self::PeringatanPiutang,
            self::BekuKredit,
            self::Komisi,
            self::KunjunganToko,
            self::EksporCoretax => null,
            self::PecahGudang => 2,
            self::PersetujuanMarketingWajib => 8,
            self::PortalPembeli,
            self::SitusPublik,
            self::PipelineImporHarga => 9,
            self::ReservasiStok,
            self::TagihSebelumKirim,
            self::HargaHanyaDariResolver => 12,
        };
    }

    public function diterapkan(): bool
    {
        return $this->berlakuMulaiFase() === null;
    }

    /** The Preferensi tab it sits on, named the way ACCURATE names its own. */
    public function tab(): string
    {
        return match ($this) {
            self::PeringatanPiutang, self::BekuKredit, self::PersetujuanMarketingWajib,
            self::PecahGudang, self::TagihSebelumKirim, self::HargaHanyaDariResolver,
            self::Komisi, self::KunjunganToko, self::PortalPembeli => 'Penjualan',
            self::ReservasiStok, self::PipelineImporHarga => 'Persediaan',
            self::EksporCoretax => 'Pajak',
            self::SitusPublik => 'Umum',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PeringatanPiutang => 'Peringatan piutang menua',
            self::BekuKredit => 'Bekukan pelanggan dengan piutang terlalu tua',
            self::Komisi => 'Komisi dan target penjualan',
            self::KunjunganToko => 'Kunjungan toko oleh sales',
            self::EksporCoretax => 'Ekspor faktur pajak (Coretax)',
            self::PortalPembeli => 'Portal pembeli',
            self::SitusPublik => 'Situs publik',
            self::PersetujuanMarketingWajib => 'Setiap order wajib disetujui marketing',
            self::PecahGudang => 'Pecah order per gudang',
            self::ReservasiStok => 'Pesan stok saat order disetujui',
            self::TagihSebelumKirim => 'Faktur terbit sebelum barang dikirim',
            self::PipelineImporHarga => 'Harga hanya lewat impor daftar harga',
            self::HargaHanyaDariResolver => 'Harga jual tidak bisa diketik manual',
        };
    }

    /** What the business loses by switching it off — the sentence on the screen. */
    public function keterangan(): string
    {
        return match ($this) {
            self::PeringatanPiutang => 'Pelanggan serta sales & marketingnya diberi tahu ketika faktur mencapai umur peringatan. '
                .'Dimatikan: tidak ada pemberitahuan otomatis.',
            self::BekuKredit => 'Pelanggan tidak bisa bertransaksi selama ada faktur melewati umur beku. '
                .'Dimatikan: hanya batas kredit yang menahan order, seperti di ACCURATE.',
            self::Komisi => 'Layar komisi & target dan laporan komisi. Dimatikan: keduanya hilang dari menu; '
                .'tarif yang tersimpan tidak dihapus.',
            self::KunjunganToko => 'Sales mencatat kunjungan toko dengan foto dan lokasi. Dimatikan: menu kunjungan hilang; '
                .'riwayat yang ada tidak dihapus.',
            self::EksporCoretax => 'Layar ekspor faktur pajak ke XML Coretax. Dimatikan: layarnya hilang dari menu.',
            self::PortalPembeli => 'Pelanggan masuk sendiri, melihat harganya, memesan ulang.',
            self::SitusPublik => 'Profil perusahaan yang terbuka untuk umum.',
            self::PersetujuanMarketingWajib => 'Order menunggu persetujuan marketing pelanggannya.',
            self::PecahGudang => 'Order yang barangnya tersebar dipecah menjadi satu transaksi per gudang.',
            self::ReservasiStok => 'Stok ditahan untuk order sejak disetujui sampai dikirim.',
            self::TagihSebelumKirim => 'Faktur diterbitkan dari order sebelum surat jalan.',
            self::PipelineImporHarga => 'Harga jual berubah hanya lewat impor daftar harga yang ditinjau.',
            self::HargaHanyaDariResolver => 'Harga di order selalu dari daftar harga dan tier pelanggan.',
        };
    }

    /** @return list<self> the switches whose code exists, in screen order */
    public static function diterapkanSemua(): array
    {
        return array_values(array_filter(self::cases(), fn (self $f) => $f->diterapkan()));
    }
}
