<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\CustomerStatement;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportTable;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * One customer's account, movement by movement.
 *
 * The only report here that takes a customer rather than covering all of them,
 * because it is the only one written to be **sent out**. Their bookkeeper has
 * a figure, we have a figure, and this is what settles it.
 *
 * Behind `canSeeCreditData()` like the ageing report, and for the same reason:
 * Sales are usually the ones who ring about an overdue invoice, and there is
 * no cost on this document.
 */
class RekeningPelanggan extends ReportPage
{
    protected static ?string $navigationLabel = 'Rekening pelanggan';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'laporan/rekening-pelanggan';

    public ?int $companyId = null;

    public string $dari = '';

    public string $sampai = '';

    public function mount(): void
    {
        $this->dari = $this->dari ?: Carbon::now()->startOfMonth()->subMonths(2)->toDateString();
        $this->sampai = $this->sampai ?: Carbon::now()->toDateString();

        // No default customer. A statement that opens on whichever company
        // happens to sort first is one somebody sends to the wrong person.
        $this->companyId ??= null;
    }

    public function getTitle(): string
    {
        return 'Rekening pelanggan';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.rekening-pelanggan';
    }

    /** @return array<int, string> */
    public function companyOptions(): array
    {
        return Company::query()
            ->orderBy('nama')
            ->pluck('nama', 'id')
            ->all();
    }

    public function company(): ?Company
    {
        return $this->companyId === null
            ? null
            : Company::query()->find($this->companyId);
    }

    /**
     * The print link, beside Unduh CSV.
     *
     * A header action rather than an anchor in the controls partial: Filament
     * styles its own buttons, and a hand-rolled one silently loses its styling
     * the moment a utility class it names is not in the compiled CSS.
     */
    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),

            Action::make('cetak')
                ->label('Cetak untuk pelanggan')
                ->icon(Heroicon::OutlinedPrinter)
                ->visible(fn () => $this->company() !== null)
                ->url(fn () => route('dokumen.rekening-pelanggan', [
                    'company' => $this->company(),
                    'dari' => $this->dari,
                    'sampai' => $this->sampai,
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public function getReport(): ReportTable
    {
        $period = Period::between($this->dari, $this->sampai);
        $company = $this->company();

        if ($company === null) {
            return new ReportTable(
                judul: 'Rekening pelanggan',
                period: $period,
                columns: [],
                rows: [],
                catatan: ['Pilih pelanggan dulu.'],
            );
        }

        return app(CustomerStatement::class)->build($company, $period);
    }
}
