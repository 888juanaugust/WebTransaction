<?php

declare(strict_types=1);

namespace App\Client\Domain\Books;

use App\Client\Domain\Ops\Integrity\LedgerIntegrity;
use App\Client\Domain\Stock\OpnameScheduler;
use App\Client\Models\FiscalYearClose;
use App\Domain\Audit\Auditor;
use App\Domain\Company\FiscalYear;
use App\Domain\Posting\PeriodLock;
use App\Models\FixedAssets\AssetDepreciation;
use App\Models\FixedAssets\FixedAsset;
use App\Models\Inventory\StockOpnameResult;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tutup buku tahunan as a lock: the Owner closes a fiscal year once every
 * month of it is closed, depreciation is posted for every month and the
 * year's semester counts are approved; no month of a closed year reopens.
 * The books keep computing retained earnings as the base does: no closing
 * entry is posted, so the statements of the year stay what they were.
 */
final class YearEnd
{
    public function __construct(private readonly PeriodLock $lock, private readonly LedgerIntegrity $integrity, private readonly ModuleRegistry $modules) {}

    /** The fiscal year a date falls in, as its start. */
    public static function startOf(DateTimeInterface|string|null $date = null): CarbonImmutable
    {
        return FiscalYear::startOf($date);
    }

    public function closed(DateTimeInterface|string $date): ?FiscalYearClose
    {
        $start = self::startOf($date);

        return FiscalYearClose::query()->whereDate('fiscal_year_start', $start->toDateString())->first();
    }

    public function isClosed(DateTimeInterface|string $date): bool
    {
        return $this->closed($date) !== null;
    }

    /** The first fiscal year not yet closed whose end has passed, else the one before today's. */
    public function nextToClose(): CarbonImmutable
    {
        $last = FiscalYearClose::query()->orderByDesc('fiscal_year_start')->first();
        if ($last !== null) {
            return CarbonImmutable::parse($last->fiscal_year_end)->addDay()->startOfDay();
        }

        return FiscalYear::startOf(FiscalYear::startOf(today())->subDay());
    }

    /** @return list<YearEndCheck> */
    public function checklist(DateTimeInterface|string $yearStart): array
    {
        $start = self::startOf($yearStart);
        $end = FiscalYear::endOf($start);
        $checks = [];

        $open = [];
        for ($m = $start; $m->lte($end); $m = $m->addMonth()) {
            if (! $this->lock->isClosed($m->toDateString())) {
                $open[] = $m->translatedFormat('F Y');
            }
        }
        $checks[] = new YearEndCheck('months', __('Every month of the year is closed'), $open === [], $open === [] ? null : __('Open: :months', ['months' => implode(', ', $open)]));

        if ($this->modules->isEnabled('fixed-assets')) {
            $missing = [];
            $assets = FixedAsset::query()->active()->get();
            foreach ($assets as $asset) {
                $acquired = CarbonImmutable::parse($asset->usage_date ?? $asset->trans_date ?? $start);
                for ($m = $start; $m->lte($end); $m = $m->addMonth()) {
                    if ($m->endOfMonth()->lt($acquired->startOfMonth())) {
                        continue;
                    }
                    if (! AssetDepreciation::query()->where('fixed_asset_id', $asset->id)->where('period', $m->format('Ym'))->exists()) {
                        $missing[] = $asset->number.' '.$m->format('M Y');
                        break;
                    }
                }
            }
            $checks[] = new YearEndCheck('depreciation', __('Depreciation is posted for every month'), $missing === [], $missing === [] ? null : __('Missing: :list', ['list' => implode(', ', array_slice($missing, 0, 6))]));
        }

        $semesters = StockOpnameResult::query()->whereHas('order', fn ($q) => $q->where('kind', OpnameScheduler::SEMESTER))
            ->whereBetween('trans_date', [$start->toDateString(), $end->toDateString()])->get();
        $unapproved = $semesters->filter(fn (StockOpnameResult $r) => ! $r->isApproved())->count();
        $checks[] = new YearEndCheck('counts', __('The year\'s semester counts are approved'), $semesters->isNotEmpty() && $unapproved === 0,
            $semesters->isEmpty() ? __('No semester count sheet was made this year') : ($unapproved > 0 ? __(':n count(s) still open', ['n' => $unapproved]) : null));

        $findings = $this->integrity->findings();
        $checks[] = new YearEndCheck('integrity', __('The ledgers reconcile'), $findings === [], $findings === [] ? null : __(':n finding(s) on the Operations screen', ['n' => count($findings)]));

        return $checks;
    }

    public function close(DateTimeInterface|string $yearStart, User $actor, ?string $notes = null): FiscalYearClose
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException(__('Only an administrator closes the year.'));
        }
        $start = self::startOf($yearStart);
        if ($this->isClosed($start)) {
            throw new RuntimeException(__('The fiscal year from :date is already closed.', ['date' => $start->toDateString()]));
        }
        $open = array_filter($this->checklist($start), fn (YearEndCheck $c) => ! $c->passed);
        if ($open !== []) {
            throw new RuntimeException(__('Not yet: :items', ['items' => implode('; ', array_map(fn (YearEndCheck $c) => $c->title.($c->finding ? ' ('.$c->finding.')' : ''), $open))]));
        }

        return DB::transaction(function () use ($start, $actor, $notes): FiscalYearClose {
            $close = FiscalYearClose::query()->create(['fiscal_year_start' => $start->toDateString(), 'fiscal_year_end' => FiscalYear::endOf($start)->toDateString(), 'closed_at' => now(), 'closed_by' => $actor->id, 'notes' => $notes]);
            Auditor::log('fiscal_year_closed', $close, $close->auditReference(), ['notes' => $notes]);

            return $close;
        });
    }

    public function reopen(DateTimeInterface|string $yearStart, User $actor, string $reason): void
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException(__('Only an administrator reopens the year.'));
        }
        if (trim($reason) === '') {
            throw new RuntimeException(__('Say why the year is reopened.'));
        }
        $close = $this->closed($yearStart) ?? throw new RuntimeException(__('That fiscal year is not closed.'));
        $later = FiscalYearClose::query()->where('fiscal_year_start', '>', $close->fiscal_year_start)->exists();
        if ($later) {
            throw new RuntimeException(__('A later year is closed; reopen that one first.'));
        }
        DB::transaction(function () use ($close, $reason): void {
            Auditor::log('fiscal_year_reopened', $close, $close->auditReference(), ['reason' => trim($reason)]);
            $close->delete();
        });
    }
}
