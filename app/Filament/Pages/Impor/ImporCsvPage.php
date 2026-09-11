<?php

declare(strict_types=1);

namespace App\Filament\Pages\Impor;

use App\Domain\Import\BarisImpor;
use App\Domain\Import\CsvTemplate;
use App\Domain\Import\TemplateKind;
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
 * Upload, look, then decide — the shape every CSV import screen shares.
 *
 * The customer and item imports were written as two full pages; by the
 * third one the copying was the bug. What differs between a staff import
 * and an opening-balance import is *which* importer reads the file, what the
 * screen is called, who may open it, and the sentence the result is
 * announced in. Everything else — the example download, the upload modal,
 * the preview table, the re-read on apply — is this class and one Blade.
 *
 * The preview is recomputed per request from the stored file rather than
 * carried in Livewire state, for the same reason as the other two: rows are
 * objects Livewire cannot round-trip, and the rows on screen and the rows
 * written must come from one reading of one file.
 */
abstract class ImporCsvPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected string $view = 'filament.pages.impor-csv';

    public ?string $berkas = null;

    public ?string $namaBerkas = null;

    public ?string $galat = null;

    /** @var list<BarisImpor>|null */
    private ?array $baris = null;

    abstract protected function kind(): TemplateKind;

    /** The object with `preview(string, User)` and `import(string, User, ?string)`. */
    abstract protected function importer(): object;

    /** The paragraph above the column table, explaining what this file is. */
    abstract public function penjelasan(): string;

    /** The confirmation modal's heading and the line under it. */
    abstract protected function konfirmasi(): array;

    /** @param array<string, int> $hasil */
    abstract protected function kalimatHasil(array $hasil): string;

    /** Where the source file is kept. */
    abstract protected function direktori(): string;

    /** @return array<string, string> */
    public function keterangan(): array
    {
        return app(CsvTemplate::class)->keterangan($this->kind());
    }

    /** @return list<BarisImpor> */
    public function rows(): array
    {
        if ($this->baris !== null) {
            return $this->baris;
        }

        if ($this->berkas === null) {
            return $this->baris = [];
        }

        try {
            return $this->baris = $this->importer()->preview(
                Storage::disk('local')->get($this->berkas) ?? '',
                auth()->user(),
            );
        } catch (Throwable $e) {
            $this->galat = $e->getMessage();

            return $this->baris = [];
        }
    }

    /** @return array{baru: int, tertahan: int} */
    public function ringkasan(): array
    {
        $baru = 0;
        $tertahan = 0;

        foreach ($this->rows() as $row) {
            $row->tertahan() ? $tertahan++ : $baru++;
        }

        return ['baru' => $baru, 'tertahan' => $tertahan];
    }

    public function unduhContoh(): StreamedResponse
    {
        $template = app(CsvTemplate::class);
        $csv = $template->toCsv($this->kind());

        return response()->streamDownload(
            fn () => print $csv,
            $template->namaBerkas($this->kind()),
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
                ->modalHeading('Unggah '.strtolower($this->kind()->label()))
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
                        ->directory($this->direktori())
                        ->preserveFilenames()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                ])
                ->action(fn (array $data) => $this->baca($data['berkas'] ?? null)),
        ];
    }

    public function baca(?string $path): void
    {
        $this->berkas = $path;
        $this->namaBerkas = $path === null ? null : basename($path);
        $this->baris = null;
        $this->galat = null;

        $this->rows();
    }

    public function imporAction(): Action
    {
        [$heading, $description] = $this->konfirmasi();

        return Action::make('impor')
            ->label('Impor sekarang')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription($description)
            ->action(function () {
                try {
                    $hasil = $this->importer()->import(
                        Storage::disk('local')->get($this->berkas) ?? '',
                        auth()->user(),
                        $this->namaBerkas,
                    );

                    Notification::make()->title($this->kalimatHasil($hasil))->success()->send();

                    // The file has been used; the screen goes back to empty
                    // so nobody imports the same list twice by pressing again.
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
