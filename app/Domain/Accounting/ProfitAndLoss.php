<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Money;
use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Laba rugi — what the business earned between two dates.
 *
 * A movement report, not a balance report: "what did we make in August" is a
 * question about what happened inside a window, which is why every figure here
 * comes from TrialBalance::forPeriod rather than from a running total.
 *
 * The expense side is split into two by the account's parent, not by its type.
 * Harga pokok penjualan and beban operasional are both `beban`, and the whole
 * point of gross profit is that they are not the same thing — cost of sales
 * moves with volume and operating expense mostly does not. The chart states
 * which is which by where an account hangs, so this reads it from there rather
 * than from a list of codes kept in step by hand.
 */
final class ProfitAndLoss
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        private readonly StatementSection $pendapatan,
        private readonly StatementSection $hargaPokok,
        private readonly StatementSection $beban,
    ) {}

    public static function forPeriod(DateTimeInterface $from, DateTimeInterface $to): self
    {
        $tb = TrialBalance::forPeriod($from, $to);
        $groups = self::groupByParent($tb, AccountType::Beban);

        /*
         * Cost of sales is whichever expense group the HPP account sits under.
         * Everything else is operating expense — including a group added later
         * that nobody thought to name here, which is the reason for taking the
         * remainder rather than listing 6-xxxx.
         */
        $hppParent = Account::byCode(AccountCode::HARGA_POKOK_PENJUALAN)->parent_id;

        $hargaPokok = $groups[$hppParent] ?? new StatementSection('Harga Pokok Penjualan', []);
        unset($groups[$hppParent]);

        $bebanLines = [];

        foreach ($groups as $section) {
            $bebanLines = [...$bebanLines, ...$section->lines];
        }

        return new self(
            from: Carbon::parse($from),
            to: Carbon::parse($to),
            pendapatan: new StatementSection('Pendapatan', self::linesOfType($tb, AccountType::Pendapatan)),
            hargaPokok: $hargaPokok,
            beban: new StatementSection('Beban Operasional', $bebanLines),
        );
    }

    /** The whole of time up to a date — what a neraca needs to close itself. */
    public static function upTo(DateTimeInterface $to): self
    {
        return self::forPeriod(new \DateTimeImmutable('1900-01-01'), $to);
    }

    public function pendapatan(): StatementSection
    {
        return $this->pendapatan;
    }

    public function hargaPokok(): StatementSection
    {
        return $this->hargaPokok;
    }

    public function beban(): StatementSection
    {
        return $this->beban;
    }

    public function totalPendapatan(): int
    {
        return $this->pendapatan->total();
    }

    public function totalHargaPokok(): int
    {
        return $this->hargaPokok->total();
    }

    /** Penjualan less what the goods cost. The number that says whether the trade works. */
    public function labaKotor(): int
    {
        return $this->totalPendapatan() - $this->totalHargaPokok();
    }

    public function totalBeban(): int
    {
        return $this->beban->total();
    }

    public function labaBersih(): int
    {
        return $this->labaKotor() - $this->totalBeban();
    }

    /** Gross margin in basis points, so the caller can render it without floats. */
    public function marginKotorBps(): ?int
    {
        if ($this->totalPendapatan() === 0) {
            return null;
        }

        return Money::mulDiv($this->labaKotor(), 10_000, $this->totalPendapatan());
    }

    /** @return list<StatementLine> */
    private static function linesOfType(TrialBalance $tb, AccountType $tipe): array
    {
        return array_map(
            fn (TrialBalanceRow $row) => new StatementLine(
                label: $row->account->nama,
                amount: $row->balance(),
                account: $row->account,
            ),
            $tb->rowsOfType($tipe),
        );
    }

    /**
     * @return array<int, StatementSection> keyed by parent account id
     */
    private static function groupByParent(TrialBalance $tb, AccountType $tipe): array
    {
        $parents = Account::query()->whereNull('parent_id')->get()->keyBy('id');
        $sections = [];

        foreach ($tb->rowsOfType($tipe) as $row) {
            $parentId = $row->account->parent_id;
            $sections[$parentId] ??= [];
            $sections[$parentId][] = new StatementLine(
                label: $row->account->nama,
                amount: $row->balance(),
                account: $row->account,
            );
        }

        $out = [];

        foreach ($sections as $parentId => $lines) {
            $out[$parentId] = new StatementSection(
                label: $parents->get($parentId)?->nama ?? 'Lain-lain',
                lines: $lines,
            );
        }

        return $out;
    }
}
