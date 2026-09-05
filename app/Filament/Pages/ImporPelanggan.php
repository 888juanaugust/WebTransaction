<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Import\CompanyImporter;
use App\Domain\Import\CompanyImportRow;
use App\Domain\Import\CsvTemplate;
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
 * Customers from a spreadsheet, with a look before the leap.
 *
 * Upload, read what each line *would* do, then decide. The preview is not
 * decoration: a bulk tool that writes as it reads gives you half a register
 * and a stack trace, and the person who uploaded eighty rows has no way to
 * tell which forty landed.
 *
 * The example file is generated from the same column list the parser reads,
 * and is offered before the upload field rather than after it — the testers'
 * complaint was that nothing told them what the file should look like, and a
 * template you find only after failing once is not much of an answer.
 */
class ImporPelanggan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENJUALAN;

    protected static ?string $navigationLabel = 'Impor pelanggan';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'impor-pelanggan';

    protected string $view = 'filament.pages.impor-pelanggan';

    /** Where the uploaded file sits while it is being looked at. */
    public ?string $berkas = null;

    public ?string $namaBerkas = null;

    /**
     * The preview, recomputed per request from the file rather than carried
     * in Livewire state.
     *
     * Livewire can only round-trip primitives, and a public property holding
     * row objects fails the *next* request rather than this one — the preview
     * renders, and pressing Impor 500s. Deriving it from the stored path also
     * means the rows on screen and the rows `import()` writes come from one
     * reading of one file, which is the same reason `import()` re-previews
     * rather than trusting what the browser hands back.
     *
     * @var list<CompanyImportRow>|null
     */
    private ?array $baris = null;

    public ?string $galat = null;

    public function getTitle(): string
    {
        return 'Impor pelanggan';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    /** @return array<string, string> */
    public function keterangan(): array
    {
        return app(CsvTemplate::class)->keterangan(TemplateKind::Pelanggan);
    }

    /** @return list<CompanyImportRow> */
    public function rows(): array
    {
        if ($this->baris !== null) {
            return $this->baris;
        }

        if ($this->berkas === null) {
            return $this->baris = [];
        }

        try {
            return $this->baris = app(CompanyImporter::class)->preview(
                Storage::disk('local')->get($this->berkas) ?? '',
                auth()->user(),
            );
        } catch (Throwable $e) {
            $this->galat = $e->getMessage();

            return $this->baris = [];
        }
    }

    public function ringkasan(): array
    {
        $baru = 0;
        $perbarui = 0;
        $tertahan = 0;

        foreach ($this->rows() as $row) {
            match ($row->status) {
                CompanyImportRow::BARU => $baru++,
                CompanyImportRow::PERBARUI => $perbarui++,
                default => $tertahan++,
            };
        }

        return ['baru' => $baru, 'perbarui' => $perbarui, 'tertahan' => $tertahan];
    }

    public function unduhContoh(): StreamedResponse
    {
        $template = app(CsvTemplate::class);
        $csv = $template->toCsv(TemplateKind::Pelanggan);

        return response()->streamDownload(
            fn () => print $csv,
            $template->namaBerkas(TemplateKind::Pelanggan),
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
                ->modalHeading('Unggah daftar pelanggan')
                ->modalDescription('CSV dengan baris judul seperti contoh. Belum ada yang '
                    .'disimpan sampai Anda menekan Impor di layar berikutnya.')
                ->schema([
                    FileUpload::make('berkas')
                        ->label('Berkas CSV')
                        ->required()
                        ->disk('local')
                        // Kept, like every other import's source file: a
                        // register somebody disputes is traceable to the
                        // bytes it came from.
                        ->directory('impor-pelanggan')
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
            ->modalHeading('Simpan ke daftar pelanggan')
            ->modalDescription('Baris yang tertahan dilewati. Yang lain disimpan sekarang.')
            ->action(function () {
                try {
                    $hasil = app(CompanyImporter::class)->import(
                        Storage::disk('local')->get($this->berkas) ?? '',
                        auth()->user(),
                        sumber: $this->namaBerkas,
                    );

                    Notification::make()
                        ->title(sprintf(
                            '%d pelanggan baru, %d diperbarui, %d tertahan.',
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
