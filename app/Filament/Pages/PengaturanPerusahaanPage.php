<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Pengaturan\PengaturanPerusahaan;
use App\Filament\Navigation\SidebarGroups;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The values only the business knows, typed here instead of over SSH.
 *
 * Everything on this page used to be an `.env` key, which made finishing
 * setup a terminal job. Now the Owner fills the form; the launch checklist,
 * the faktur's payment block, the portal and the public site read the new
 * values the same second. `.env` remains the fallback for a blank field.
 *
 * Owner only. The bank account here is where customer money goes — the
 * field a fraud would edit — so every change is audited with old and new.
 */
class PengaturanPerusahaanPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENGATURAN;

    protected static ?string $navigationLabel = 'Pengaturan perusahaan';

    protected static ?int $navigationSort = 85;

    protected static ?string $slug = 'pengaturan-perusahaan';

    protected string $view = 'filament.pages.pengaturan-perusahaan';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        // The Owner: the same hand that grants every other permission.
        return auth()->user()?->role()->canManageStaff() ?? false;
    }

    public function getTitle(): string
    {
        return 'Pengaturan perusahaan';
    }

    public function mount(): void
    {
        $pengaturan = app(PengaturanPerusahaan::class);

        $this->form->fill(
            collect(array_keys(PengaturanPerusahaan::PETA))
                ->mapWithKeys(fn (string $kunci) => [$kunci => $pengaturan->nilai($kunci)])
                ->all(),
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Rekening perusahaan')
                    ->description(
                        'Tercetak di setiap faktur dan di portal sebagai tujuan transfer '
                        .'pelanggan. Nomor yang salah mengirim uang pelanggan ke rekening '
                        .'orang lain — periksa terhadap buku bank, bukan terhadap ingatan.'
                    )
                    ->columns(3)
                    ->schema([
                        TextInput::make('rekening_bank')->label('Bank')->maxLength(30),
                        TextInput::make('rekening_nomor')->label('Nomor rekening')->maxLength(40),
                        TextInput::make('rekening_atas_nama')->label('Atas nama')->maxLength(120),
                    ]),

                Section::make('Identitas perusahaan')
                    ->description('Tampil di situs publik dan dokumen cetak. Wajib benar sebelum pendaftaran PSE.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('perusahaan_nib')->label('NIB')->maxLength(30),
                        TextInput::make('perusahaan_npwp')->label('NPWP perusahaan')->maxLength(30),
                        TextInput::make('perusahaan_alamat')->label('Alamat')->maxLength(200)->columnSpanFull(),
                        TextInput::make('perusahaan_kota')->label('Kota')->maxLength(60),
                        TextInput::make('perusahaan_telepon')->label('Telepon')->maxLength(30),
                        TextInput::make('perusahaan_whatsapp')->label('WhatsApp')->maxLength(30),
                        TextInput::make('perusahaan_email')->label('Email')->email()->maxLength(120),
                    ]),

                Section::make('Identitas penjual — faktur pajak')
                    ->description('Yang tercetak sebagai penjual pada ekspor faktur pajak (Coretax). Pastikan ke akuntan.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('pajak_penjual_npwp')->label('NPWP wajib pajak')->maxLength(30),
                        TextInput::make('pajak_penjual_nama')->label('Nama wajib pajak')->maxLength(120),
                    ]),

                Section::make('Nilai komersial — syarat penjualan')
                    ->description(
                        'Dua keputusan bisnis yang tercetak di halaman syarat penjualan. '
                        .'Angka yang tidak berniat ditagih lebih buruk daripada tidak ada '
                        .'angka — seluruh dokumen jadi terlihat hiasan.'
                    )
                    ->columns(2)
                    ->schema([
                        TextInput::make('legal_denda_persen')
                            ->label('Denda keterlambatan (% per bulan)')
                            ->numeric()->minValue(0)->maxValue(10),
                        TextInput::make('legal_batas_klaim_hari')
                            ->label('Batas klaim barang (hari setelah terima)')
                            ->numeric()->minValue(1)->maxValue(30),
                    ]),

                Section::make('Mitra di situs publik')
                    ->description(
                        'Nama yang tampil di halaman Partners situs publik. Menyebut '
                        .'perusahaan sebagai mitra adalah klaim tentang hubungan bisnis '
                        .'yang nyata — cantumkan hanya yang benar-benar setuju tampil. '
                        .'Hapus semua baris bila belum ada yang mau dicantumkan; '
                        .'halaman itu sah tanpa mitra.'
                    )
                    ->schema([
                        Repeater::make('mitra_json')
                            ->label('Daftar mitra')
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Tambah mitra')
                            ->schema([
                                TextInput::make('nama')->label('Nama perusahaan')
                                    ->required()->maxLength(120),
                                TextInput::make('negara')->label('Negara')->maxLength(60),
                                TextInput::make('sejak')->label('Sejak (tahun)')->maxLength(10),
                                TextInput::make('bidang')->label('Bidang')->maxLength(80),
                                Textarea::make('deskripsi')->label('Deskripsi singkat')
                                    ->rows(2)->maxLength(300)->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Promo di beranda')
                    ->description(
                        'Slide yang berjalan di bagian atas halaman depan situs publik. Satu '
                        .'gambar per slide, lebar — kira-kira 3:1 — dengan judul dan satu kalimat '
                        .'di atasnya. Slide yang tidak aktif disimpan tapi tidak tampil; tanpa '
                        .'slide aktif, beranda tampil tanpa promo, bukan dengan kotak kosong.'
                    )
                    ->schema([
                        Repeater::make('promo_json')
                            ->label('Daftar promo')
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable()
                            ->addActionLabel('Tambah promo')
                            ->schema([
                                TextInput::make('judul')->label('Judul')
                                    ->required()->maxLength(80),
                                TextInput::make('tautan')->label('Tautan (opsional)')
                                    ->url()->maxLength(200)
                                    ->helperText('Alamat lengkap, mis. https://… Tombol "Selengkapnya" tampil bila diisi.'),
                                Textarea::make('teks')->label('Satu kalimat')
                                    ->rows(2)->maxLength(200)->columnSpanFull(),
                                FileUpload::make('gambar')->label('Gambar')
                                    ->disk('public')->directory('promo')
                                    ->image()->maxSize(2048)
                                    ->imagePreviewHeight('120')
                                    ->required()
                                    ->helperText('JPG atau PNG, maksimal 2 MB. Dilayani dari server kami sendiri.'),
                                Toggle::make('aktif')->label('Tampilkan')->default(true),
                            ]),
                    ]),
            ]);
    }

    public function simpan(): void
    {
        app(PengaturanPerusahaan::class)->simpan(
            $this->form->getState(),
            auth()->user(),
        );

        Notification::make()
            ->title('Pengaturan tersimpan')
            ->body('Faktur, portal, situs publik, dan checklist peluncuran membaca nilai baru sekarang juga.')
            ->success()
            ->send();
    }
}
