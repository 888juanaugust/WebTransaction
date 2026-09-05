<?php

declare(strict_types=1);

namespace App\Filament\Pages\Akuntansi;

use App\Domain\Accounting\FiscalCalendar;
use App\Domain\Accounting\PeriodCloser;
use App\Domain\Accounting\ProfitAndLoss;
use App\Domain\Integrity\IntegrityFinding;
use App\Domain\Integrity\LedgerIntegrity;
use App\Filament\Navigation\SidebarGroups;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodReopening;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Closing the books, month by month.
 *
 * The screen has one job beyond the two buttons: to make it obvious what a
 * close *does*, before somebody does it. Closing a month is easy to click and
 * awkward to undo, and the thing it prevents — a back-dated document quietly
 * restating a month already reported — is invisible until it has happened.
 * So each row states what the month contains and what closing it will lock.
 */
class TutupBuku extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::BUKU_BESAR;

    protected static ?string $navigationLabel = 'Tutup buku';

    protected static ?int $navigationSort = 75;

    protected static ?string $slug = 'akuntansi/tutup-buku';

    protected string $view = 'filament.pages.akuntansi.tutup-buku';

    public function getTitle(): string
    {
        return 'Tutup buku';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canClosePeriod() ?? false;
    }

    /**
     * Every month, oldest first, with what it holds and whether it is locked.
     *
     * @return list<array{
     *     tanggal: Carbon, label: string, closed: bool, period: ?AccountingPeriod,
     *     pendapatan: int, laba: int, closable: bool, reopenable: bool, yearEnd: bool
     * }>
     */
    public function getRows(): array
    {
        $calendar = app(FiscalCalendar::class);
        $next = $calendar->nextToClose();

        $rows = [];
        $mostRecentClosed = $calendar->lastClosed();

        foreach ($calendar->months() as $month) {
            $period = $calendar->periodFor($month);

            $pl = ProfitAndLoss::forPeriod($month, $month->copy()->endOfMonth());

            $rows[] = [
                'tanggal' => $month,
                'label' => $month->translatedFormat('F Y'),
                'closed' => $period !== null,
                'period' => $period,
                'pendapatan' => $pl->totalPendapatan(),
                'laba' => $pl->labaBersih(),
                'closable' => $next !== null && $next->equalTo($month),
                /*
                 * Only the most recent closed month may be reopened — periods
                 * reopen newest first — so it is the only row that offers to.
                 * An action that lets somebody pick a month it will then
                 * refuse teaches them the rule by failing.
                 */
                'reopenable' => $mostRecentClosed !== null
                    && $mostRecentClosed->tahun === $month->year
                    && $mostRecentClosed->bulan === $month->month,
                'yearEnd' => $month->month === 12,
            ];
        }

        return array_reverse($rows);
    }

    /** The month that may be closed right now, if any. */
    public function nextToClose(): ?Carbon
    {
        return app(FiscalCalendar::class)->nextToClose();
    }

    public function openFrom(): ?Carbon
    {
        return app(FiscalCalendar::class)->openFrom();
    }

    /**
     * What closing this month would post, when it is a December.
     *
     * Built by PeriodCloser itself rather than recomputed here — a preview
     * assembled from a second copy of the rule is a preview that can lie.
     */
    public function yearEndPreview(): ?array
    {
        $next = $this->nextToClose();

        if ($next === null || $next->month !== 12) {
            return null;
        }

        $draft = app(PeriodCloser::class)->previewYearEnd($next->year);

        return $draft === null ? null : [
            'tahun' => $next->year,
            'total' => $draft->totalDebit(),
            'baris' => count($draft->lines()),
        ];
    }

    /** @return list<AccountingPeriodReopening> */
    public function getReopenings(): array
    {
        return AccountingPeriodReopening::query()
            ->with('reopenedBy')
            ->latest('id')
            ->limit(10)
            ->get()
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tutup')
                ->label(fn () => $this->nextToClose() === null
                    ? 'Tidak ada periode yang bisa ditutup'
                    : 'Tutup '.$this->nextToClose()->translatedFormat('F Y'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('primary')
                ->disabled(fn () => $this->nextToClose() === null)
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Tutup '.($this->nextToClose()?->translatedFormat('F Y') ?? ''))
                ->modalDescription(fn () => $this->confirmationText())
                ->modalSubmitActionLabel('Ya, tutup periode')
                ->schema(fn () => array_values(array_filter([
                    Textarea::make('catatan')
                        ->label('Catatan (opsional)')
                        ->placeholder('mis. sudah direkonsiliasi dengan rekening koran')
                        ->rows(2),

                    /*
                     * Only when there is something to force past, and only for
                     * the seat allowed to force it. Finance sees the findings
                     * on the page and the refusal from the domain; the box for
                     * a reason is not offered to somebody who cannot use it.
                     */
                    $this->temuanPenghalang() !== [] && (auth()->user()?->role()->canReopenPeriod() ?? false)
                        ? Textarea::make('alasan_terpaksa')
                            ->label('Alasan menutup walaupun buku belum cocok')
                            ->helperText('Wajib. Tercatat di log audit bersama daftar selisihnya.')
                            ->required()
                            ->rows(2)
                        : null,
                ])))
                ->action(function (array $data) {
                    $next = $this->nextToClose();

                    if ($next === null) {
                        return;
                    }

                    try {
                        $period = app(PeriodCloser::class)->close(
                            $next->year,
                            $next->month,
                            auth()->user(),
                            $data['catatan'] ?: null,
                            $data['alasan_terpaksa'] ?? null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Periode tidak bisa ditutup')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title("{$period->label()} ditutup")
                        ->body($period->closing_entry_id !== null
                            ? 'Tahun buku ikut ditutup: laba dipindahkan ke Laba Ditahan.'
                            : 'Tidak ada lagi jurnal yang bisa diposting ke bulan ini.')
                        ->send();
                }),
        ];
    }

    /**
     * A reopen action per closed row, rather than one action with a picker.
     *
     * Reopening is only ever legal for the most recent closed month, and an
     * action that lets somebody choose a month it will then refuse is an
     * action that teaches them the rule by failing.
     */
    public function bukaAction(): Action
    {
        return Action::make('buka')
            ->label('Buka kembali')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('danger')
            ->visible(fn () => auth()->user()?->role()->canReopenPeriod() ?? false)
            ->requiresConfirmation()
            ->modalHeading('Buka kembali periode')
            ->modalDescription(
                'Angka bulan ini mungkin sudah dilaporkan ke akuntan. Membukanya kembali '
                .'memungkinkan jurnal baru masuk dan mengubah angka tersebut. '
                .'Kalau ini penutupan akhir tahun, jurnal penutupnya akan dibalik.'
            )
            ->modalSubmitActionLabel('Ya, buka kembali')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan')
                    ->required()
                    ->rows(2)
                    ->placeholder('mis. faktur pemasok bulan lalu baru diterima hari ini'),
            ])
            ->action(function (array $data, array $arguments) {
                try {
                    app(PeriodCloser::class)->reopen(
                        (int) $arguments['tahun'],
                        (int) $arguments['bulan'],
                        auth()->user(),
                        $data['alasan'],
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Periode tidak bisa dibuka')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title('Periode dibuka kembali')->send();
            });
    }

    /**
     * What would stop this month being closed, if anything.
     *
     * Shown on the page rather than only raised as a refusal, so the
     * accountant who came here to close August finds out why before pressing
     * the button — and the Owner deciding to close anyway can read exactly
     * what they are signing for.
     *
     * @return list<IntegrityFinding>
     */
    public function temuanPenghalang(): array
    {
        return $this->temuan ??= app(LedgerIntegrity::class)->blockingFindings();
    }

    /** @var list<IntegrityFinding>|null */
    private ?array $temuan = null;

    public function confirmationText(): string
    {
        $next = $this->nextToClose();

        if ($next === null) {
            return '';
        }

        $text = "Setelah ditutup, tidak ada jurnal bertanggal {$next->translatedFormat('F Y')} "
            .'atau sebelumnya yang bisa diposting — termasuk faktur, penerimaan barang dan '
            .'pembayaran yang dokumennya bertanggal bulan itu.';

        $preview = $this->yearEndPreview();

        if ($preview !== null) {
            $text .= " Ini juga penutupan tahun {$preview['tahun']}: satu jurnal penutup akan "
                .'memindahkan seluruh pendapatan dan beban tahun itu ke Laba Ditahan.';
        }

        return $text;
    }
}
