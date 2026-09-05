<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Explorer\ExplorerColumn;
use App\Domain\Explorer\ExplorerDataset;
use App\Filament\Navigation\SidebarGroups;
use App\Models\Invoice;
use App\Models\SavedView;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The question nobody anticipated.
 *
 * Every other report answers a fixed question well. This one answers "every
 * faktur for these four shops, oldest first" — asked once a quarter, never the
 * same way twice, and previously answered by somebody exporting everything and
 * filtering in Excel, which is how a spreadsheet of live customer data ends up
 * on a laptop.
 *
 * Three things keep it honest:
 *
 * **It shows rows, never sums.** No totals, no derived figures, no margin. A
 * row here is a row in the register — which is what makes it safe to export
 * and safe to argue from, and what stops it quietly becoming a second,
 * disagreeing set of reports.
 *
 * **Each dataset carries its own gate.** Without that this screen is the back
 * door around every role rule in the system: a warehouse account reading
 * invoice totals by choosing a different item from a dropdown. Columns are
 * gated too, so a reader who may not see prices does not get them in the CSV
 * either.
 *
 * **A saved view stores the question, not the answer.** Filters and sort, not
 * rows. Opened next month it reads next month's data; a cached answer would go
 * stale silently and be believed anyway.
 */
class Penjelajah extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::LAPORAN;

    protected static ?string $navigationLabel = 'Penjelajah data';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'laporan/penjelajah';

    protected string $view = 'filament.pages.laporan.penjelajah';

    public string $dataset = '';

    public function mount(): void
    {
        $tersedia = ExplorerDataset::tersedia();

        abort_if($tersedia === [], 403);

        $this->dataset = $this->dataset ?: $tersedia[0]->value;
    }

    public function getTitle(): string
    {
        return 'Penjelajah data';
    }

    public static function canAccess(): bool
    {
        return ExplorerDataset::tersedia() !== [];
    }

    public function kumpulan(): ExplorerDataset
    {
        $set = ExplorerDataset::tryFrom($this->dataset);

        // A dataset picked from a stale page, or typed in by hand, must not
        // become a way past the gate.
        abort_if($set === null || ! $set->canAccess(), 403);

        return $set;
    }

    /** @return array<string, string> */
    public function pilihanDataset(): array
    {
        return collect(ExplorerDataset::tersedia())
            ->mapWithKeys(fn (ExplorerDataset $d) => [$d->value => $d->label()])
            ->all();
    }

    /**
     * Changing dataset clears the arrangement rather than carrying it over —
     * a "status = lunas" filter means nothing on the barang list, and a
     * silently dropped filter is worse than an obviously empty one.
     */
    public function updatedDataset(): void
    {
        $this->susun(null, null, '');
    }

    /**
     * Put the table into an arrangement, controls included.
     *
     * Filters here are deferred — the form edits `tableDeferredFilters` and
     * only copies into `tableFilters` when Terapkan is pressed. Setting one
     * without the other leaves the query filtered and the controls showing
     * something else, or the reverse, and both read as the screen ignoring
     * you.
     *
     * @param  array<string, mixed>|null  $filters
     */
    private function susun(?array $filters, ?string $urutan, string $pencarian): void
    {
        $this->tableSort = $urutan;
        $this->tableSearch = $pencarian;

        if ($filters === null) {
            // Changing dataset: let the table boot its own filters afresh.
            $this->tableFilters = null;
            $this->tableDeferredFilters = null;
            $this->resetTable();

            return;
        }

        /*
         * Filters here are deferred: the controls edit `tableDeferredFilters`
         * and Terapkan copies that into `tableFilters`. So a saved view is
         * applied the same way a person applies one — merged into the deferred
         * state, then applied — rather than by assigning the property, which
         * the schema silently overwrites with its defaults on the next render.
         *
         * Merged rather than replaced because the deferred state carries every
         * filter the table has, and handing it only the two a saved view names
         * would drop the rest of the structure the controls are bound to.
         */
        $this->tableDeferredFilters = array_replace_recursive(
            $this->tableDeferredFilters ?? [],
            $filters,
        );

        $this->applyTableFilters();
    }

    public function table(Table $table): Table
    {
        $set = $this->kumpulan();

        return $table
            ->query(fn (): Builder => $set->query())
            ->columns($this->kolomUntuk($set))
            ->filters($this->saringanUntuk($set))
            ->emptyStateHeading('Tidak ada baris yang cocok')
            ->emptyStateDescription('Longgarkan filternya, atau ganti kumpulan datanya.')
            ->paginated([25, 50, 100, 250]);
    }

    /** @return list<TextColumn> */
    private function kolomUntuk(ExplorerDataset $set): array
    {
        $searchable = $set->searchable();

        return collect($set->columns())
            ->filter(fn (ExplorerColumn $c) => $c->isVisible())
            ->map(function (ExplorerColumn $c) use ($searchable) {
                $column = TextColumn::make($c->key)
                    ->label($c->label)
                    ->state(fn ($record) => $c->format($c->state($record)))
                    ->wrap($c->key === 'description');

                if ($c->sortable()) {
                    $column->sortable();
                }

                if (in_array($c->key, $searchable, true)) {
                    $column->searchable();
                }

                return $column;
            })
            ->values()
            ->all();
    }

    /** @return list<mixed> */
    private function saringanUntuk(ExplorerDataset $set): array
    {
        $filters = [];

        foreach ($set->filters() as $name => $spec) {
            $filters[] = match ($spec['type']) {
                'select' => SelectFilter::make($name)
                    ->label($spec['label'])
                    ->options($spec['options'] ?? [])
                    ->attribute($spec['column'] ?? $name),

                'toggle' => Filter::make($name)
                    ->label($spec['label'])
                    ->query(fn (Builder $q) => $this->applyToggle($set, $name, $q)),

                default => Filter::make($name)
                    ->schema([
                        DatePicker::make('dari')->label('Dari')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('sampai')->label('Sampai')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['dari'] ?? null,
                            fn (Builder $qq, $d) => $qq->whereDate($spec['column'], '>=', $d))
                        ->when($data['sampai'] ?? null,
                            fn (Builder $qq, $d) => $qq->whereDate($spec['column'], '<=', $d))),
            };
        }

        return $filters;
    }

    /**
     * The toggles that are not a plain column comparison.
     *
     * Kept here rather than in the dataset definition because they are
     * queries, and the dataset stays a description of shape.
     */
    private function applyToggle(ExplorerDataset $set, string $name, Builder $query): Builder
    {
        return match ([$set, $name]) {
            [ExplorerDataset::Faktur, 'jatuh_tempo'] => $query
                ->where('status', Invoice::STATUS_OPEN)
                ->whereDate('due_date', '<', now()->toDateString()),
            [ExplorerDataset::Barang, 'aktif'] => $query->where('aktif', true),
            default => $query,
        };
    }

    // ------------------------------------------------------------ saved views

    /** @return Collection<int, SavedView> */
    public function tampilanTersimpan()
    {
        return SavedView::query()
            ->readableBy((int) auth()->id())
            ->where('dataset', $this->dataset)
            ->with('user')
            ->orderBy('nama')
            ->get();
    }

    public function simpanAction(): Action
    {
        return Action::make('simpan')
            ->label('Simpan filter')
            ->icon(Heroicon::OutlinedBookmark)
            ->color('gray')
            ->modalHeading('Simpan susunan filter ini')
            ->modalDescription('Yang disimpan adalah pertanyaannya — filter, urutan dan '
                .'pencarian — bukan hasilnya. Dibuka bulan depan, isinya data bulan depan.')
            ->schema([
                TextInput::make('nama')->label('Nama')->required()->maxLength(80)
                    ->placeholder('mis. Faktur lewat jatuh tempo'),
                Toggle::make('dibagikan')->label('Bagikan ke rekan')
                    ->helperText('Rekan bisa memakainya; hanya Anda yang bisa menghapusnya.'),
            ])
            ->action(function (array $data) {
                SavedView::query()->updateOrCreate(
                    [
                        'user_id' => auth()->id(),
                        'dataset' => $this->dataset,
                        'nama' => $data['nama'],
                    ],
                    [
                        'filters' => $this->tableDeferredFilters ?? $this->tableFilters,
                        'urutan' => $this->tableSort,
                        'pencarian' => $this->tableSearch ?: null,
                        'dibagikan' => (bool) ($data['dibagikan'] ?? false),
                    ],
                );

                Notification::make()->title('Filter disimpan')->success()->send();
            });
    }

    public function pakaiAction(): Action
    {
        return Action::make('pakai')
            ->label('Pakai')
            ->link()
            ->size('xs')
            ->action(function (array $arguments) {
                $view = SavedView::query()->readableBy((int) auth()->id())
                    ->find($arguments['view'] ?? 0);

                if ($view === null || $view->dataset !== $this->dataset) {
                    return;
                }

                $this->susun(
                    $view->filters ?? [],
                    $view->urutan,
                    (string) ($view->pencarian ?? ''),
                );
            });
    }

    public function hapusTampilanAction(): Action
    {
        return Action::make('hapusTampilan')
            ->label('Hapus')
            ->link()
            ->size('xs')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Hapus filter tersimpan')
            ->action(function (array $arguments) {
                $view = SavedView::query()->find($arguments['view'] ?? 0);

                // Shared or not, a view belongs to whoever saved it.
                if ($view === null || ! $view->milik((int) auth()->id())) {
                    Notification::make()->title('Hanya pemiliknya yang bisa menghapus')
                        ->danger()->send();

                    return;
                }

                $view->delete();
                Notification::make()->title('Filter dihapus')->success()->send();
            });
    }

    // ------------------------------------------------------------------ CSV

    protected function getHeaderActions(): array
    {
        return [
            $this->simpanAction(),

            Action::make('unduh')
                ->label('Unduh CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action('unduh'),
        ];
    }

    /**
     * The rows on screen, in the order they are on screen.
     *
     * Built from the table's own filtered and sorted query rather than a
     * fresh one, so what downloads is what was being looked at — an export
     * that quietly ignores the filters is how somebody emails the whole
     * customer list believing it is four rows.
     */
    public function unduh(): StreamedResponse
    {
        $set = $this->kumpulan();

        $columns = collect($set->columns())
            ->filter(fn (ExplorerColumn $c) => $c->isVisible())
            ->values();

        $query = $this->getFilteredSortedTableQuery();

        $name = str($set->label().' '.now()->format('Y-m-d'))->slug()->append('.csv')->value();

        return response()->streamDownload(function () use ($columns, $query) {
            $out = fopen('php://output', 'w');

            // A BOM, so Excel in this locale opens it as UTF-8 rather than
            // mangling every description with an accent in it.
            fwrite($out, "\u{FEFF}");

            fputcsv($out, $columns->map(fn (ExplorerColumn $c) => $c->label)->all(), ';', '"', '\\');

            $query?->chunk(500, function ($records) use ($out, $columns) {
                foreach ($records as $record) {
                    fputcsv(
                        $out,
                        $columns->map(fn (ExplorerColumn $c) => $c->forCsv($c->state($record)))->all(),
                        ';', '"', '\\',
                    );
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
