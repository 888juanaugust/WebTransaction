<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Models\CashBank\Giro;
use App\Models\Company\CalendarEvent;
use App\Models\Company\RecurringTransaction;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Sales\SalesInvoice;
use App\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;

/**
 * What a stretch of days holds (a month, a week, the agenda): invoices
 * falling due, giros maturing, recurring transactions scheduled, month ends,
 * and the company's own notes.
 */
final class CalendarFeed
{
    /** @return array<string, list<array{kind: string, title: string, url: ?string}>> date → events */
    public static function month(int $year, int $month): array
    {
        $from = CarbonImmutable::create($year, $month, 1);

        return self::between($from, $from->endOfMonth());
    }

    /** @return array<string, list<array{kind: string, title: string, url: ?string}>> date → events, for any range (a week, the agenda) */
    /** @var list<callable(CarbonImmutable, CarbonImmutable, callable(string, string, string, ?string=): void): void> */
    private static array $feeders = [];

    /** @var array<string, array{label: string|\Closure, colour: string}> */
    private static array $extraKinds = [];

    /**
     * A module adds its own events: the feeder is called with the range and the same $add(date, kind, title, url)
     * the base uses, and its kind joins the legend with the label and colour given.
     */
    public static function extend(string $kind, string|\Closure $label, string $colour, callable $feeder): void
    {
        self::$extraKinds[$kind] = ['label' => $label, 'colour' => $colour];
        self::$feeders[] = $feeder;
    }

    /** The kinds a module added, for the legend. @return array<string, array{label: string, colour: string}> */
    public static function extraKinds(): array
    {
        // A label given as a closure is read in the viewer's language, not the language of the boot.
        return array_map(fn (array $kind) => ['label' => $kind['label'] instanceof \Closure ? (string) ($kind['label'])() : $kind['label'], 'colour' => $kind['colour']], self::$extraKinds);
    }

    public static function between(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $events = [];
        $add = function (string $date, string $kind, string $title, ?string $url = null) use (&$events): void {
            $events[$date][] = ['kind' => $kind, 'title' => $title, 'url' => $url];
        };

        // Each kind only for someone who may see it on its own screen, and only in their branches.
        $user = auth()->user();
        $akses = app(HakAkses::class);
        $sees = fn (MenuKey ...$screens): bool => $user === null || collect($screens)->contains(fn (MenuKey $screen) => $akses->allows($user, $screen, Hak::View));
        $range = [$from->toDateString(), $until->toDateString()];

        if ($sees(MenuKey::SalesInvoices)) {
            foreach (BranchLimit::apply(SalesInvoice::query(), $user)->with('customer')->where('payment_status', '!=', 'paid')->whereBetween('due_date', $range)->get() as $invoice) {
                $add($invoice->due_date->toDateString(), 'receivable', __('Due: :number · :party', ['number' => $invoice->number, 'party' => $invoice->customer?->name ?? '']));
            }
        }
        if ($sees(MenuKey::PurchaseInvoices)) {
            foreach (BranchLimit::apply(PurchaseInvoice::query(), $user)->with('vendor')->where('payment_status', '!=', 'paid')->whereBetween('due_date', $range)->get() as $invoice) {
                $add($invoice->due_date->toDateString(), 'payable', __('Pay: :number · :party', ['number' => $invoice->number, 'party' => $invoice->vendor?->name ?? '']));
            }
        }
        foreach (Giro::query()->outstanding()->with('source')->whereBetween('due_date', $range)->get() as $giro) {
            $branch = $giro->source?->getAttribute('branch_id');
            $visible = $giro->isIncoming() ? $sees(MenuKey::SalesReceipts, MenuKey::Receipts) : $sees(MenuKey::PurchasePayments, MenuKey::Payments);
            if ($visible && BranchLimit::allows($user, $branch !== null ? (int) $branch : null)) {
                $add($giro->due_date->toDateString(), 'giro', $giro->isIncoming()
                    ? __('Giro in: :number · :party', ['number' => $giro->number, 'party' => $giro->party_name ?? ''])
                    : __('Giro out: :number · :party', ['number' => $giro->number, 'party' => $giro->party_name ?? '']));
            }
        }
        if ($sees(MenuKey::RecurringTransactions)) {
            foreach (RecurringTransaction::query()->where('status', 'active')->whereBetween('next_run_on', $range)->get() as $recurring) {
                $add($recurring->next_run_on->toDateString(), 'recurring', __('Recurring: :name', ['name' => $recurring->name]));
            }
        }
        foreach (CalendarEvent::query()->whereBetween('starts_on', $range)->orderBy('starts_on')->get() as $event) {
            $add($event->starts_on->toDateString(), 'note', $event->title);
        }
        $monthEnd = app(ModuleRegistry::class)->isEnabled('fixed-assets') ? __('Month end: close the period and run depreciation') : __('Month end: close the period');
        for ($end = $from->endOfMonth()->startOfDay(); $end->lte($until); $end = $end->addDay()->endOfMonth()->startOfDay()) {
            $add($end->toDateString(), 'period', $monthEnd);
        }
        foreach (self::$feeders as $feeder) {
            $feeder($from, $until, $add);
        }
        ksort($events);

        return $events;
    }
}
