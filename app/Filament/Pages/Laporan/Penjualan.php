<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Regions\RegionContext;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportTable;
use App\Domain\Reporting\SalesDimension;
use App\Domain\Reporting\SalesReport;
use App\Models\Region;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

use function Filament\Support\original_request;

/**
 * What we sold, grouped six ways.
 *
 * Defaults to last month by customer: the question somebody sits down with.
 * The current month would invite comparing a part-finished period against
 * whole ones and concluding sales have collapsed.
 *
 * One page, three menu entries. The grouping switch has always answered
 * "omset per sales" and "barang paling laku", and the owner's revision list
 * asked for both as reports — which they are, behind a dropdown nobody was
 * told about. The dropdown stays; the two groupings people actually go
 * looking for also get a row of their own in the Laporan menu, deep-linked
 * through `?dimensi=`. A report that exists but cannot be found does not
 * exist for the person looking.
 */
class Penjualan extends ReportPage
{
    protected static ?string $navigationLabel = 'Penjualan';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'laporan/penjualan';

    /**
     * The two groupings that get their own menu row, and what the row says.
     *
     * @var array<string, string> dimensi value => label
     */
    public const PINTASAN = [
        'sales' => 'Omset per sales',
        'barang' => 'Barang paling laku',
    ];

    // The period too: "omset per sales, Agustus" is a link somebody pastes
    // into a chat, and a link that opens on a different month is a wrong link.
    #[Url(except: '')]
    public string $dari = '';

    #[Url(except: '')]
    public string $sampai = '';

    /**
     * In the URL, so a menu row — or a bookmark, or a link in a chat — can
     * open the report already grouped. `except` keeps the default out of the
     * address bar so the plain report's URL stays plain.
     */
    #[Url(except: 'pelanggan')]
    public string $dimensi = SalesDimension::Pelanggan->value;

    public function mount(): void
    {
        $default = Period::lastMonth();

        $this->dari = $this->dari ?: $default->from->toDateString();
        $this->sampai = $this->sampai ?: $default->to->toDateString();

        // A typo in the address bar falls back rather than 500s.
        if (SalesDimension::tryFrom($this->dimensi) === null) {
            $this->dimensi = SalesDimension::Pelanggan->value;
        }
    }

    /**
     * The plain report, then one row per shortcut, each active only for its
     * own grouping — so the highlighted row and the dropdown always agree.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $pattern = static::getNavigationItemActiveRoutePattern();
        $dimensiDiminta = fn (): ?string => original_request()->query('dimensi');

        $items = [
            NavigationItem::make(static::getNavigationLabel())
                ->key(static::class)
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->sort(static::getNavigationSort())
                ->url(static::getNavigationUrl())
                ->isActiveWhen(fn (): bool => original_request()->routeIs($pattern)
                    && ! array_key_exists((string) $dimensiDiminta(), self::PINTASAN)),
        ];

        $sort = static::getNavigationSort();

        foreach (self::PINTASAN as $dimensi => $label) {
            $sort++;

            $items[] = NavigationItem::make($label)
                ->key(static::class.'@'.$dimensi)
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->sort($sort)
                ->url(static::getUrl(['dimensi' => $dimensi]))
                ->isActiveWhen(fn (): bool => original_request()->routeIs($pattern)
                    && $dimensiDiminta() === $dimensi);
        }

        return $items;
    }

    public function getTitle(): string
    {
        return 'Laporan penjualan';
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.penjualan';
    }

    /** @return array<string, string> */
    public function getDimensiOptions(): array
    {
        return collect(SalesDimension::cases())
            ->mapWithKeys(fn (SalesDimension $d) => [$d->value => $d->label()])
            ->all();
    }

    public function getDimensi(): SalesDimension
    {
        return SalesDimension::from($this->dimensi);
    }

    public function getReport(): ReportTable
    {
        return app(SalesReport::class)->build(
            Period::between(Carbon::parse($this->dari), Carbon::parse($this->sampai)),
            $this->getDimensi(),
            withCost: $this->withCost(),
        );
    }

    public function chartsFor(ReportTable $report): array
    {
        $charts = [app(SalesReport::class)->chart($report, $this->getDimensi())];

        /*
         * Sales per wilayah, only for a viewer who already sees every region
         * — the Owner on "Semua wilayah", or marketing. For anyone pinned,
         * drawing other regions' sales here would be this page quietly
         * undoing the region scope.
         */
        if (app(RegionContext::class)->regionId() === null && Region::query()->count() > 1) {
            $charts[] = app(SalesReport::class)->regionChart($report->period);
        }

        return $charts;
    }
}
