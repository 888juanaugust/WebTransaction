<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Neraca — what the business owns and owes on one day.
 *
 * The line that makes it work is the one with no account behind it. Profit
 * only becomes equity when the books are closed, and nothing here has ever
 * been closed, so the accumulated result sits in the income and expense
 * accounts where a balance sheet cannot see it. Assets would exceed
 * liabilities plus equity by exactly that amount.
 *
 * So the result is computed and shown in equity, split in two:
 *
 *   - **Laba tahun berjalan** — this fiscal year's result so far.
 *   - **Laba ditahan belum ditutup** — every year before it that was never
 *     closed. Nil once a period close exists and has been run; until then it
 *     is the honest name for what is otherwise a mystery in the equity total.
 *
 * The fiscal year is the calendar year, which is the default for a PT or CV
 * unless the DJP has approved otherwise.
 */
final class BalanceSheet
{
    private function __construct(
        public readonly Carbon $asOf,
        private readonly StatementSection $aset,
        private readonly StatementSection $kewajiban,
        private readonly StatementSection $modal,
        public readonly int $labaTahunBerjalan,
        public readonly int $labaDitahanBelumDitutup,
    ) {}

    public static function asOf(DateTimeInterface $tanggal): self
    {
        $asOf = Carbon::parse($tanggal);
        $tb = TrialBalance::asOf($asOf);

        $awalTahun = $asOf->copy()->startOfYear();

        $tahunIni = ProfitAndLoss::forPeriod($awalTahun, $asOf)->labaBersih();
        $seluruhnya = ProfitAndLoss::upTo($asOf)->labaBersih();

        $modalLines = self::linesOfType($tb, AccountType::Modal);
        $sebelumnya = $seluruhnya - $tahunIni;

        if ($sebelumnya !== 0) {
            $modalLines[] = new StatementLine('Laba ditahan belum ditutup', $sebelumnya);
        }

        $modalLines[] = new StatementLine('Laba tahun berjalan', $tahunIni);

        return new self(
            asOf: $asOf,
            aset: new StatementSection('Aset', self::linesOfType($tb, AccountType::Aset)),
            kewajiban: new StatementSection('Kewajiban', self::linesOfType($tb, AccountType::Kewajiban)),
            modal: new StatementSection('Modal', $modalLines),
            labaTahunBerjalan: $tahunIni,
            labaDitahanBelumDitutup: $sebelumnya,
        );
    }

    public function aset(): StatementSection
    {
        return $this->aset;
    }

    public function kewajiban(): StatementSection
    {
        return $this->kewajiban;
    }

    public function modal(): StatementSection
    {
        return $this->modal;
    }

    public function totalAset(): int
    {
        return $this->aset->total();
    }

    public function totalKewajiban(): int
    {
        return $this->kewajiban->total();
    }

    public function totalModal(): int
    {
        return $this->modal->total();
    }

    public function totalKewajibanDanModal(): int
    {
        return $this->totalKewajiban() + $this->totalModal();
    }

    /**
     * One account's balance as this statement reports it.
     *
     * Reads the rendered lines rather than the ledger again, so a caller
     * cannot be told one figure by the report and a different one by a second
     * query — which is precisely how a balance sheet and the thing checking it
     * end up disagreeing.
     *
     * Returns 0 for an account that is not on this statement: income and
     * expense accounts genuinely have no balance-sheet balance.
     */
    public function balanceOf(string $kode): int
    {
        foreach ([$this->aset, $this->kewajiban, $this->modal] as $section) {
            foreach ($section->lines as $line) {
                if ($line->kode() === $kode) {
                    return $line->amount;
                }
            }
        }

        return 0;
    }

    /**
     * The check. It cannot fail unless the trial balance does, which is the
     * point — printing it is what makes that guarantee visible rather than
     * assumed.
     */
    public function isBalanced(): bool
    {
        return $this->totalAset() === $this->totalKewajibanDanModal();
    }

    public function selisih(): int
    {
        return $this->totalAset() - $this->totalKewajibanDanModal();
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
}
