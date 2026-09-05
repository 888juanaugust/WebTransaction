<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Import\CsvTemplate;
use App\Domain\Import\ProductImporter;
use App\Domain\Import\ProductImportRow;
use App\Domain\Import\TemplateKind;
use App\Filament\Navigation\SidebarGroups;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The catalogue from a spreadsheet — and the only way a SKU is created.
 *
 * The one-at-a-time form is gone: `ProductResource::canCreate()` is false and
 * items arrive here, in bulk, one file for the whole list. A person adding a
 * single SKU makes a one-row file, which is clumsier than a form and buys
 * something worth more — every item in the catalogue came through one
 * validated door, so `satuan_dasar` and `qty_per_ctn` have one place that
 * checks them rather than two that drift.
 *
 * Deliberately the twin of Impor pelanggan: upload, read what each line
 * *would* do, then decide. A bulk tool that writes as it reads gives you half
 * a catalogue and a stack trace, and the person who uploaded four hundred
 * rows has no way to tell which two hundred landed.
 *
 * Filed under Gudang beside Impor harga & barang rather than under Penjualan
 * with the customer import: both screens here are the catalogue-keeper's, and
 * the pair of them being adjacent is what makes the difference between them
 * — this one is what a thing *is*, that one is what it *costs* — legible.
 */
class ImporBarang extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::GUDANG;

    protected static ?string $navigationLabel = 'Impor barang';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'impor-barang';

    protected string $view = 'filament.pages.impor-barang';

    /** Where the uploaded file sits while it is being looked at. */
    public ?string $berkas = null;

    public ?string $namaBerkas = null;

    /**
     * The preview, recomputed per request from the file rather than carried
     * in Livewire state — see ImporPelanggan for why a public property of row
     * objects fails the *next* request rather than this one.
     *
     * @var list<ProductImportRow>|null
     */
    private ?array $baris = null;

    public ?string $galat = null;

    public function getTitle(): string
    {
        return 'Impor barang';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canManageCatalogue() ?? false;
    }

    /** @return array<string, string> */
    public function keterangan(): array
    {
        return app(CsvTemplate::class)->keterangan(TemplateKind::Barang);
    }

    /** @return list<ProductImportRow> */
    public function rows(): array
    {
        if ($this->baris !== null) {
            return $this->baris;
        }

        if ($this->berkas === null) {
            return $this->baris = [];
        }

        try {
            return $this->baris = app(ProductImporter::class)->preview(
                Storage::disk('local')->get($this->berkas) ?? '',
                auth()->user(),
            );
        } catch (Throwable $e) {
            $this->galat = $e->getMessage();

            return $this->baris = [];
        }
    }

    /** @return array{baru: int, perbarui: int, tertahan: int} */
    public function ringkasan(): array
    {
        $baru = 0;
        $perbarui = 0;
        $tertahan = 0;

        foreach ($this->rows() as $row) {
            match ($row->status) {
                ProductImportRow::BARU => $baru++,
                ProductImportRow::PERBARUI => $perbarui++,
                default => $tertahan++,
            };
        }

        return ['baru' => $baru, 'perbarui' => $perbarui, 'tertahan' => $tertahan];
    }

    public function unduhContoh(): StreamedResponse
    {
        $template = app(CsvTemplate::class);
        $csv = $template->toCsv(TemplateKind::Barang);

        return response()->streamDownload(
            fn () => print $csv,
            $template->namaBerkas(TemplateKind::Barang),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contoh')
                ->label('Unduh contoh CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action('unduhContoh'),

            Action::make('unggah')
                ->label('Unggah berkas')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading('Unggah daftar barang')
                ->modalDescription('CSV dengan baris judul seperti contoh. Belum ada yang '
                    .'disimpan sampai Anda menekan Impor di layar berikutnya.')
                ->schema([
                    FileUpload::make('berkas')
                        ->label('Berkas CSV')
                        ->required()
                        ->disk('local')
                        // Kept, like every other import's source file: a
                        // catalogue somebody disputes is traceable to the
                        // bytes it came from.
                        ->directory('impor-barang')
                        ->preserveFilenames()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                ])
                ->action(fn (array $data) => $this->baca($data['berkas'] ?? null)),
        ];
    }

    /** Point the screen at an uploaded file. The preview follows on render. */
    public function baca(?string $path): void
    {
        $this->berkas = $path;
        $this->namaBerkas = $path === null ? null : basename($path);
        $this->baris = null;
        $this->galat = null;

        // Read it now so a bad file is reported on upload rather than
        // silently rendering an empty preview.
        $this->rows();
    }

    public function imporAction(): Action
    {
        return Action::make('impor')
            ->label('Impor sekarang')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->requiresConfirmation()
            ->modalHeading('Simpan ke katalog')
            ->modalDescription('Baris yang tertahan dilewati. Yang lain disimpan sekarang. '
                .'Harga tidak ikut — itu lewat Impor harga & barang.')
            ->action(function () {
                try {
                    $hasil = app(ProductImporter::class)->import(
                        Storage::disk('local')->get($this->berkas) ?? '',
                        auth()->user(),
                        sumber: $this->namaBerkas,
                    );

                    Notification::make()
                        ->title(sprintf(
                            '%d barang baru, %d diperbarui, %d tertahan.',
                            $hasil['baru'], $hasil['diperbarui'], $hasil['tertahan'],
                        ))
                        ->success()
                        ->send();

                    // The file has been used; the screen goes back to empty so
                    // nobody imports the same list twice by pressing again.
                    $this->berkas = null;
                    $this->namaBerkas = null;
                    $this->baris = null;
                } catch (Throwable $e) {
                    Notification::make()->title('Tidak bisa diimpor')
                        ->body($e->getMessage())->danger()->send();
                }
            });
    }
}
