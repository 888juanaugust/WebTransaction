<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Audit\Auditor;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Payroll\Art21Slips;
use App\Models\Tax\TaxFiling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The Art. 21 files handed to the tax office: the month's BPMP slips (every
 * employee taxed at the TER rate that month) and the year's A1 slips. Each
 * export is kept as a tax filing, numbered from the withholding-slip series,
 * with its file and the employees it holds, and logged.
 */
final class Pph21Filings
{
    public const MONTHLY = 'bpmp';

    public const ANNUAL = 'bpa1';

    public function __construct(private readonly Art21Slips $slips, private readonly Pph21XmlWriter $writer, private readonly NumberGenerator $numbers) {}

    /** @return list<array<string, mixed>> the month's rows that go on BPMP slips */
    public function monthlyRows(int $year, int $month): array
    {
        return array_values(array_filter($this->slips->month($year, $month), fn (array $r) => $r['method'] === 'ter'));
    }

    /** @return list<array<string, mixed>> the year's A1 slips: permanent employees whose year (or employment) ended in it */
    public function annualSlips(int $year): array
    {
        return array_values(array_filter($this->slips->year($year), fn (array $s) => $s['permanent'] && $s['complete'] && $s['ptkp_status'] !== null));
    }

    public function exportMonthly(int $year, int $month): TaxFiling
    {
        $rows = $this->monthlyRows($year, $month);
        if ($rows === []) {
            throw new RuntimeException(__('No employee had tax withheld at the TER rate in :month/:year.', ['month' => $month, 'year' => $year]));
        }
        $end = CarbonImmutable::create($year, $month, 1)->endOfMonth();

        return $this->store(self::MONTHLY, $year, $month, $end, $this->writer->monthly($rows, $year, $month),
            array_map(fn (array $r) => [$r['employee']->id, $r['gross'], $r['tax']], $rows));
    }

    public function exportAnnual(int $year): TaxFiling
    {
        $slips = $this->annualSlips($year);
        if ($slips === []) {
            throw new RuntimeException(__('No A1 slip is complete for :year yet: December, or an employee\'s last month, must be paid first.', ['year' => $year]));
        }

        return $this->store(self::ANNUAL, $year, 12, CarbonImmutable::create($year, 12, 31), $this->writer->annual($slips),
            array_map(fn (array $s) => [$s['employee']->id, $s['annual']['gross'], $s['tax_due']], $slips));
    }

    /** @param  list<array{0: int, 1: int, 2: int}>  $employees  id, gross, tax */
    private function store(string $kind, int $year, int $month, CarbonImmutable $date, string $content, array $employees): TaxFiling
    {
        $series = $this->numbers->defaultSeries(TransactionType::WithholdingSlip21, auth()->user())
            ?? throw new RuntimeException(__('No number series for :type.', ['type' => TransactionType::WithholdingSlip21->getLabel()]));
        $fileName = sprintf('%s-%04d%s-%s.xml', $kind, $year, $kind === self::MONTHLY ? sprintf('%02d', $month) : '', now()->format('Ymd-His'));
        $path = "tax-filings/{$fileName}";
        Storage::disk('local')->put($path, $content);

        return DB::transaction(function () use ($kind, $year, $month, $date, $employees, $series, $fileName, $path): TaxFiling {
            $filing = TaxFiling::query()->create([
                'number' => $this->numbers->next($series, $date),
                'kind' => $kind,
                'format' => TaxFiling::CORETAX,
                'period_year' => $year,
                'period_month' => $month,
                'from_date' => $kind === self::MONTHLY ? $date->startOfMonth()->toDateString() : $date->startOfYear()->toDateString(),
                'to_date' => $date->toDateString(),
                'file_name' => $fileName,
                'file_path' => $path,
                'document_count' => count($employees),
                'dpp_total' => array_sum(array_column($employees, 1)),
                'tax_total' => array_sum(array_column($employees, 2)),
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);
            foreach ($employees as [$id, $gross, $tax]) {
                $filing->documents()->create(['document_type' => 'employee', 'document_id' => $id, 'dpp' => $gross, 'tax' => $tax]);
            }
            Auditor::log('tax_filing_exported', $filing, $fileName, ['employees' => count($employees), 'gross' => $filing->dpp_total, 'tax' => $filing->tax_total], $date->toDateString());

            return $filing;
        });
    }
}
