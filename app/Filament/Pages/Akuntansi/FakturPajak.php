<?php

declare(strict_types=1);

namespace App\Filament\Pages\Akuntansi;

use App\Domain\Tax\FakturExporter;
use App\Domain\Tax\FakturExportPreview;
use App\Domain\Tax\NsfpRecorder;
use App\Models\FakturExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Filing output VAT: the month, the file, and the numbers that come back.
 *
 * The screen leads with what would be **left out**, not with what is ready.
 * A filing that quietly omits an invoice is a sale we collected PPN on and did
 * not report, and nobody notices until the tax office compares our figures
 * with the customer's — so the blocked list is above the download button, with
 * the invoice number and what to do about it on each row.
 */
class FakturPajak extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static \UnitEnum|string|null $navigationGroup = 'Buku besar';

    protected static ?string $navigationLabel = 'Faktur pajak';

    protected static ?int $navigationSort = 78;

    protected static ?string $slug = 'akuntansi/faktur-pajak';

    protected string $view = 'filament.pages.akuntansi.faktur-pajak';

    /** The tax period being looked at, as `Y-m`. */
    public string $periode = '';

    public function mount(): void
    {
        $this->periode = $this->periode ?: now()->subMonthNoOverflow()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Faktur pajak';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canExportFaktur() ?? false;
    }

    /**
     * Months with invoices in them, plus the one being looked at.
     *
     * The default is *last* month, because that is what somebody sits down to
     * file. Offering this month first would invite filing a period that is
     * still being invoiced into.
     *
     * @return array<string, string>
     */
    public function getPeriodeOptions(): array
    {
        $periods = app(FakturExporter::class)->availablePeriods();

        if (! in_array($this->periode, $periods, true)) {
            $periods[] = $this->periode;
        }

        rsort($periods);

        return collect($periods)
            ->mapWithKeys(fn (string $p) => [
                $p => Carbon::createFromFormat('Y-m', $p)->translatedFormat('F Y'),
            ])
            ->all();
    }

    public function getPreview(): FakturExportPreview
    {
        [$tahun, $masa] = $this->periodParts();

        return app(FakturExporter::class)->preview($tahun, $masa);
    }

    /** Past filings for this period, newest first. */
    public function getExports(): array
    {
        [$tahun, $masa] = $this->periodParts();

        return FakturExport::query()
            ->with('createdBy')
            ->where('tahun_pajak', $tahun)
            ->where('masa_pajak', $masa)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * Write the file for this period.
     *
     * Deliberately not a download-only action: the file is recorded and kept,
     * and the download comes afterwards from the filing's own row. Somebody
     * will need to produce the exact file that was uploaded, months later, and
     * regenerating it then would use whatever the code does by then.
     */
    public function eksporAction(): Action
    {
        return Action::make('ekspor')
            ->label('Buat file ekspor')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Buat file ekspor faktur pajak')
            ->modalDescription(function () {
                $preview = $this->getPreview();

                return sprintf(
                    '%d faktur akan masuk file ini. %s Periksa dulu daftar yang terhalang — '
                    .'faktur yang tertinggal berarti PPN yang sudah dipungut tapi tidak dilaporkan.',
                    count($preview->siap),
                    $preview->jumlahTerhalang() > 0
                        ? $preview->jumlahTerhalang().' faktur TIDAK akan masuk.'
                        : 'Tidak ada yang terhalang.',
                );
            })
            ->schema([
                Checkbox::make('ulang')
                    ->label('Termasuk faktur yang sudah pernah diekspor')
                    ->helperText('Untuk pembetulan. Biasanya biarkan kosong.'),

                Textarea::make('catatan')
                    ->label('Catatan')
                    ->rows(2)
                    ->placeholder('mis. pembetulan atas penolakan NPWP pelanggan'),
            ])
            ->modalSubmitActionLabel('Buat file')
            ->action(function (array $data) {
                [$tahun, $masa] = $this->periodParts();

                try {
                    $export = app(FakturExporter::class)->export(
                        $tahun,
                        $masa,
                        auth()->user(),
                        termasukSudahDiekspor: (bool) ($data['ulang'] ?? false),
                        catatan: $data['catatan'] ?: null,
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('File tidak bisa dibuat')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Ekspor {$export->nomor} dibuat")
                    ->body($export->jumlah_faktur.' faktur. Unduh filenya, lalu unggah ke Coretax.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Paste the numbers Coretax handed back.
     *
     * Two columns, reference and serial, whatever separates them. Parsing a
     * returned file properly would mean knowing that file's format, which is
     * the same thing we do not know about the outbound one — and a paste out
     * of a spreadsheet works regardless of what the tax office changes.
     */
    public function catatNsfpAction(): Action
    {
        return Action::make('catatNsfp')
            ->label('Catat nomor seri')
            ->icon(Heroicon::OutlinedHashtag)
            ->color('gray')
            ->modalHeading('Catat nomor seri faktur pajak')
            ->modalDescription(
                'Tempel dua kolom: nomor faktur kita, lalu nomor seri dari Coretax. '
                .'Dipisah tab, koma atau titik koma.'
            )
            ->schema([
                Textarea::make('pasted')
                    ->label('Nomor seri')
                    ->rows(10)
                    ->required()
                    ->placeholder("INV-202608-0001\t0400002512345678\nINV-202608-0002\t0400002512345679"),
            ])
            ->modalSubmitActionLabel('Catat')
            ->action(function (array $data, array $arguments) {
                $export = FakturExport::query()->find($arguments['export'] ?? null);

                if ($export === null) {
                    Notification::make()->title('Ekspor tidak ditemukan')->danger()->send();

                    return;
                }

                $recorder = app(NsfpRecorder::class);

                try {
                    $written = $recorder->record(
                        $export,
                        $recorder->parse($data['pasted']),
                        auth()->user(),
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak ada yang dicatat')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($written.' nomor seri dicatat')
                    ->body($export->refresh()->menungguNsfp().' faktur masih menunggu.')
                    ->success()
                    ->send();
            });
    }

    /** @return array{0: int, 1: int} tahun, masa */
    private function periodParts(): array
    {
        $date = Carbon::createFromFormat('Y-m', $this->periode);

        return [(int) $date->year, (int) $date->month];
    }
}
